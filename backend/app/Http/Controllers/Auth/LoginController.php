<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Infrastructure\Security\TotpAuthenticator;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primer paso del acceso: credencial mas segundo factor.
 *
 * Es previo al flujo OAuth2, no parte de el. El servidor de autorizacion solo
 * emite un codigo cuando ya hay un usuario autenticado en la sesion; este
 * endpoint es quien lo autentica, y por eso es aqui donde se exige el TOTP
 * obligatorio de los perfiles administrativos (RS-01, Fase 3 §4.4).
 *
 * Terminado este paso, la SPA continua con GET /api/v1/auth/authorize llevando
 * su code_challenge, y despues canjea el codigo en POST /api/v1/auth/token con
 * el code_verifier. La contrasena nunca entra en el flujo OAuth2: el flujo de
 * contrasena (password grant) esta desactivado a proposito.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly TotpAuthenticator $totp,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        // Limite por correo y por IP a la vez: por correo frena el ataque a una
        // cuenta concreta desde muchas direcciones, y por IP frena el barrido
        // de muchas cuentas desde una sola (RS-10).
        // SHA-256 y no SHA-1, aunque aqui solo se trate de derivar una clave
        // de contador: la regla de seguridad no negociable 6 no admite
        // excepciones por uso "poco importante", y las excepciones son
        // exactamente lo que hace que un algoritmo prohibido siga vivo.
        $throttleKey = hash('sha256', mb_strtolower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            return $this->failure(
                'Demasiados intentos. Espere unos minutos antes de reintentar.',
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        // Se calcula un hash aunque el usuario no exista para que el tiempo de
        // respuesta no distinga un correo registrado de uno que no lo esta.
        $passwordIsValid = $user !== null
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], Hash::make('golsfintech-decoy'));

        if ($user === null || ! $passwordIsValid) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);
            $this->record(AuditEventType::AuthenticationFailed, $request->ip(), $credentials['email'], $user?->id);

            // Un unico mensaje para credencial inexistente y credencial
            // incorrecta: distinguirlos permite enumerar usuarios.
            return $this->failure('Credenciales invalidas.', Response::HTTP_UNAUTHORIZED);
        }

        if ($user->requiresTwoFactor()) {
            if (! $user->hasTwoFactorEnabled()) {
                RateLimiter::hit($throttleKey, decaySeconds: 300);
                $this->record(AuditEventType::TwoFactorChallengeFailed, $request->ip(), $credentials['email'], $user->id);

                // El perfil administrativo sin segundo factor dado de alta no
                // entra: se le niega el acceso, no se le exime del control.
                return $this->failure('Credenciales invalidas.', Response::HTTP_UNAUTHORIZED);
            }

            $code = $credentials['totp_code'] ?? null;

            if ($code === null || ! $this->totp->verify($user->two_factor_secret, $code)) {
                RateLimiter::hit($throttleKey, decaySeconds: 300);
                $this->record(AuditEventType::TwoFactorChallengeFailed, $request->ip(), $credentials['email'], $user->id);

                return $this->failure('Credenciales invalidas.', Response::HTTP_UNAUTHORIZED);
            }

            $user->forceFill(['two_factor_verified_at' => Carbon::now()])->save();
        }

        RateLimiter::clear($throttleKey);

        // Sesion del guard web: es la que consulta el servidor de autorizacion
        // para saber quien esta concediendo el permiso.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->record(AuditEventType::AuthenticationSucceeded, $request->ip(), $credentials['email'], $user->id);

        return new JsonResponse([
            'message' => 'Autenticacion correcta.',
            'two_factor_verified' => $user->requiresTwoFactor(),
        ]);
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }

    /**
     * El correo va como actor. SensitiveDataMasker enmascara los metadatos en
     * el constructor de AuditEvent, de modo que ninguna CURP, RFC ni PAN puede
     * colarse por aqui.
     */
    private function record(AuditEventType $type, ?string $ip, string $email, ?int $userId): void
    {
        $this->auditLogger->append(new AuditEvent(
            eventType: $type,
            affectedEntity: 'User',
            affectedEntityId: $userId,
            prospectId: null,
            context: new AuditContext(actor: $email, ipAddress: $ip),
            eventAt: new DateTimeImmutable,
        ));
    }
}
