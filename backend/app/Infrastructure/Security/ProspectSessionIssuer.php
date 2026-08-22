<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Access\Role;
use App\Domain\Prospect\Prospect;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emite y renueva la sesion del prospecto anonimo (P1) y la convierte en sesion
 * de cliente cuando el credito se autoriza (P6).
 *
 * ## Por que hay un usuario detras de un visitante anonimo
 *
 * Toda la API se apoya en `users.prospect_id` para saber de quien es el
 * expediente: `IdentityDocumentController` deriva el prospecto del usuario
 * autenticado y nunca de un campo de la peticion, que es lo que evita el IDOR
 * clasico (CWE-639). Un visitante sin usuario obligaria a aceptar el
 * identificador del expediente por parametro, y ese identificador pasaria a ser
 * una credencial de portador. Se crea, pues, un usuario de rol `prospect`
 * anclado al expediente.
 *
 * **Ese usuario no puede iniciar sesion con contrasena.** Su correo esta en el
 * dominio reservado `.invalid` (RFC 2606), que por definicion no existe ni
 * puede recibir correo, y su contrasena es una cadena aleatoria de 64
 * caracteres que no conoce nadie y que no se devuelve en ninguna respuesta. La
 * unica credencial es el token, y el token caduca.
 *
 * ## Vigencia
 *
 * 30 minutos por token, no una sesion larga: la Fase 1 estima el tramite
 * completo en menos de 5 minutos (RNF-02). La renovacion emite uno nuevo y
 * **no revoca el anterior**, que caduca por su cuenta: revocarlo dejaria sin
 * credencial a las peticiones ya en vuelo —P3 consulta el estado del OCR en
 * bucle— y el limite de 30 minutos se sigue cumpliendo para cada token.
 *
 * En la promocion a cliente si se revoca, y ahi es lo correcto: es un cambio de
 * privilegios, no una prorroga.
 */
final readonly class ProspectSessionIssuer
{
    /** Solo el propio expediente en tramite. */
    public const PROSPECT_SCOPE = 'prospect-session';

    /** El expediente ya resuelto: linea de credito y tarjeta propias. */
    public const CUSTOMER_SCOPE = 'customer-session';

    public function __construct(
        private int $ttlMinutes,
        private int $renewBeforeSeconds,
    ) {}

    /** Crea el usuario anclado al expediente y le abre la sesion. */
    public function openSessionFor(Prospect $prospect): IssuedSession
    {
        $prospectId = $prospect->id();

        if ($prospectId === null) {
            throw new RuntimeException('No se puede abrir sesion sobre un prospecto sin persistir.');
        }

        $user = new User;
        $user->forceFill([
            'name' => 'Solicitud en tramite',
            // Dominio reservado por RFC 2606: no existe y no puede recibir
            // correo, de modo que este usuario no tiene canal de recuperacion
            // ni puede confundirse con una cuenta real.
            'email' => sprintf('prospect-%s@prospect.invalid', $prospect->publicId()->value),
            'password' => Hash::make(Str::random(64)),
            'role' => Role::Prospect,
            'prospect_id' => $prospectId,
        ])->save();

        return $this->issue($user, self::PROSPECT_SCOPE);
    }

    /** Renovacion silenciosa: token nuevo, mismo alcance, sin revocar el viejo. */
    public function renew(User $user): IssuedSession
    {
        return $this->issue($user, $this->scopeFor($user));
    }

    /**
     * P6: el prospecto pasa a cliente.
     *
     * Se revocan TODOS sus tokens de prospecto antes de emitir el de cliente.
     * Dejar vivo el anterior seria una escalada silenciosa: el mismo token
     * pasaria a valer para endpoints que no existian cuando se emitio.
     */
    public function promoteToCustomer(User $user, int $customerId): IssuedSession
    {
        $user->tokens()->where('revoked', false)->update(['revoked' => true]);

        $user->forceFill([
            'role' => Role::Customer,
            'customer_id' => $customerId,
        ])->save();

        return $this->issue($user->refresh(), self::CUSTOMER_SCOPE);
    }

    private function scopeFor(User $user): string
    {
        return $user->role === Role::Customer ? self::CUSTOMER_SCOPE : self::PROSPECT_SCOPE;
    }

    private function issue(User $user, string $scope): IssuedSession
    {
        $result = $user->createToken($scope, [$scope]);
        $expiresAt = $result->token->expires_at;

        return new IssuedSession(
            accessToken: $result->accessToken,
            expiresAt: DateTimeImmutable::createFromInterface($expiresAt),
            expiresInSeconds: $this->ttlMinutes * 60,
            renewBeforeSeconds: $this->renewBeforeSeconds,
            scope: $scope,
        );
    }
}
