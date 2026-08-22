<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\CreditApplicationRecord;
use App\Infrastructure\Security\CaptchaVerifier;
use App\Infrastructure\Security\FirstPartyClient;
use App\Infrastructure\Security\ProspectSessionIssuer;
use App\Infrastructure\Security\SimulatedCaptchaVerifier;
use App\Infrastructure\Security\TotpAuthenticator;
use App\Infrastructure\Security\TurnstileCaptchaVerifier;
use App\Models\User;
use App\Policies\CreditApplicationPolicy;
use DateInterval;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Passport\Passport;

/**
 * Control de acceso: permisos como Gates, politicas por entidad y ajustes del
 * servidor OAuth2.
 */
final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El flujo de codigo de dispositivo no forma parte del diseno: no hay
        // ningun cliente sin navegador en la solucion. Se desactiva aqui, en
        // register(), porque Passport publica sus rutas en boot() y para
        // entonces la decision debe estar tomada. Superficie que no existe no
        // se puede atacar.
        Passport::$deviceCodeGrantEnabled = false;

        // La pantalla de consentimiento se omite para el cliente de primera
        // parte; vease el comentario de FirstPartyClient.
        Passport::useClientModel(FirstPartyClient::class);

        // El backend es una API: no sirve HTML. La pantalla de consentimiento
        // la dibuja la SPA a partir de esta respuesta, no el servidor.
        //
        // Con el cliente de primera parte de GolsFintech no llega a usarse
        // —FirstPartyClient omite el consentimiento—, pero la vista tiene que
        // existir: AuthorizationController la recibe por inyeccion, de modo
        // que sin ella /authorize falla antes de decidir si hay que mostrarla.
        Passport::authorizationView(
            static fn (array $parameters): JsonResponse => new JsonResponse([
                'client' => [
                    'id' => $parameters['client']->getKey(),
                    'name' => $parameters['client']->name,
                ],
                'scopes' => array_map(
                    static fn ($scope): string => $scope->id,
                    $parameters['scopes']
                ),
                'auth_token' => $parameters['authToken'],
            ])
        );

        $this->app->singleton(ProspectSessionIssuer::class, static function ($app): ProspectSessionIssuer {
            $session = $app['config']->get('security.prospect_session');

            return new ProspectSessionIssuer(
                ttlMinutes: $session['ttl_minutes'],
                renewBeforeSeconds: $session['renew_before_seconds'],
            );
        });

        // Mismo patron de tabla de drivers que los siete puertos: cambiar de
        // proveedor de CAPTCHA es configuracion, no codigo.
        $this->app->singleton(CaptchaVerifier::class, static function ($app): CaptchaVerifier {
            $captcha = $app['config']->get('security.captcha');

            return match ($captcha['driver']) {
                'simulated' => new SimulatedCaptchaVerifier($captcha['simulated_token']),
                'turnstile' => new TurnstileCaptchaVerifier(
                    secret: (string) $captcha['turnstile']['secret'],
                    verifyUrl: $captcha['turnstile']['verify_url'],
                    timeoutSeconds: $captcha['turnstile']['timeout_seconds'],
                ),
                default => throw new InvalidArgumentException(
                    'Driver de CAPTCHA desconocido: '.$captcha['driver'].'. Opciones: simulated, turnstile.'
                ),
            };
        });

        $this->app->singleton(TotpAuthenticator::class, function ($app): TotpAuthenticator {
            $totp = $app['config']->get('security.totp');

            return new TotpAuthenticator(
                algorithm: $totp['algorithm'],
                digits: $totp['digits'],
                period: $totp['period'],
                window: $totp['window'],
            );
        });
    }

    public function boot(): void
    {
        $this->registerPermissionGates();

        Gate::policy(CreditApplicationRecord::class, CreditApplicationPolicy::class);

        $this->configureOAuthServer();
    }

    /**
     * Cada permiso del dominio se publica como Gate con su propio nombre, de
     * modo que una ruta pueda exigirlo con `can:audit_log.read`.
     *
     * No se define ningun Gate::before que conceda todo a un rol: un "el
     * administrador puede todo" anula de un plumazo las restricciones
     * explicitas de la Fase 3 §4.9 —que el administrador no toque las reglas
     * del motor, que los ingresos queden restringidos— y deja el resto de la
     * matriz como decoracion.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                static fn (User $user): bool => $user->hasPermission($permission)
            );
        }
    }

    private function configureOAuthServer(): void
    {
        $oauth = $this->app['config']->get('security.oauth');

        // Vida corta del token de acceso: si se filtra, la ventana de uso es de
        // minutos. La renovacion la resuelve el refresh_token, que si esta
        // sujeto a revocacion en el servidor.
        Passport::tokensExpireIn(
            new DateInterval('PT'.$oauth['access_token_ttl_minutes'].'M')
        );
        Passport::refreshTokensExpireIn(
            new DateInterval('P'.$oauth['refresh_token_ttl_days'].'D')
        );

        // Catalogo de scopes. El unico que existe hoy es el que usa el worker
        // para devolver el resultado del OCR: un token de client_credentials no
        // debe poder hacer nada mas que eso, y sin catalogo un scope pedido a
        // mano no significaria nada. La SPA no pide scopes: opera con los
        // permisos del usuario, que es otra capa distinta.
        Passport::tokensCan([
            'ocr-result' => 'Devolver al backend el resultado de una extraccion OCR',
            ProspectSessionIssuer::PROSPECT_SCOPE => 'Operar sobre la propia solicitud en tramite',
            ProspectSessionIssuer::CUSTOMER_SCOPE => 'Consultar la propia linea de credito y tarjeta',
        ]);

        // Vigencia de la sesion del prospecto y del cliente. Es la vigencia de
        // los tokens personales, que son los unicos que emite este servidor sin
        // pasar por el flujo de codigo de autorizacion: los de P1. Corta a
        // proposito —el tramite completo se estima en menos de 5 minutos
        // (RNF-02)— y con renovacion silenciosa mientras haya actividad.
        Passport::personalAccessTokensExpireIn(
            new DateInterval('PT'.$this->app['config']->get('security.prospect_session.ttl_minutes').'M')
        );

        // El secreto de los clientes lo hashea Passport 13 siempre, sin
        // opcion de guardarlo en claro: una copia de oauth_clients no basta
        // para suplantar a un cliente confidencial. La SPA, ademas, no tiene
        // secreto en absoluto, por ser un cliente publico.

        // NO se llama a Passport::enableImplicitGrant() ni a
        // Passport::enablePasswordGrant(). Ambos estan desactivados por defecto
        // y deben seguir estandolo:
        //
        // - El flujo implicito entrega el token en la redireccion, expuesto en
        //   el historial del navegador y en los registros intermedios. La
        //   Fase 3 §4.4 lo descarta expresamente.
        // - El flujo de contrasena obliga a la SPA a manejar la credencial del
        //   usuario en claro y es incompatible con el segundo factor
        //   obligatorio (RS-01).
        //
        // El middleware EnforcePkceS256 rechaza ademas response_type=token y
        // grant_type=implicit en la propia peticion, y ImplicitGrantTest lo
        // comprueba: si alguien habilitara el flujo, la suite se pone en rojo.
    }
}
