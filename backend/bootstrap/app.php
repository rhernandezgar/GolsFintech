<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceReadOnlyRole;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Excepciones que el framework ya traduce a un codigo de estado correcto y a un
 * cuerpo generico. La normalizacion de abajo las deja pasar tal cual.
 *
 * La lista es explicita y no una heuristica a proposito: el primer intento
 * atrapaba «todo lo que no fuera HttpException» y convirtio los 401 en 500,
 * reabriendo VUL-14 desde el otro lado. Quince pruebas lo detectaron. Una lista
 * que hay que ampliar a mano falla del lado seguro —una excepcion nueva se
 * normaliza a 500 generico— en vez del lado que rompe la autenticacion.
 */
const FRAMEWORK_RENDERED_EXCEPTIONS = [
    HttpExceptionInterface::class,          // 4xx y 5xx explicitos, incluido abort()
    AuthenticationException::class,         // 401
    AuthorizationException::class,          // 403
    ValidationException::class,             // 422 con `errors`
    ModelNotFoundException::class,          // 404
    RecordsNotFoundException::class,        // 404
    TokenMismatchException::class,          // 419
];

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // El catalogo de servicios de la Fase 3 versiona la API bajo /api/v1.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | GLOBALES, no de grupo, y esto no es un detalle de estilo.
        |
        | Registrados en los grupos `web` y `api` se saltaban un caso entero: la
        | peticion a una ruta que NO EXISTE. El router lanza el 404 antes de
        | resolver el grupo, asi que esa respuesta salia sin cabeceras de
        | seguridad y sin identificador de correlacion. Lo detecto la prueba que
        | ejerce un 404 real —no la que comprueba que el middleware esta
        | registrado, que pasaba igual—, y es otra vez la leccion de VUL-14:
        | verificar la configuracion no es verificar el comportamiento.
        |
        | Van los primeros de la pila para que un rechazo temprano —el limitador
        | de peticiones, por ejemplo— tampoco se quede sin ellos: son justo las
        | respuestas que soporte necesita poder rastrear y las que un atacante
        | provoca a proposito.
        */
        $middleware->prepend([
            SecurityHeaders::class,
            AssignCorrelationId::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'read-only' => EnforceReadOnlyRole::class,

            // Para las rutas que consume un proceso y no una persona: exige un
            // token de client_credentials con el scope indicado.
            'client' => EnsureClientIsResourceOwner::class,

            // Alcance del token de usuario. La sesion del prospecto se emite
            // acotada a `prospect-session`, de modo que aunque el rol del
            // usuario cambiara, el token no abre nada que no estuviera en su
            // alcance cuando se emitio.
            'scopes' => CheckToken::class,
        ]);

        /*
        | Un invitado NO se redirige a ninguna parte: se le responde 401.
        |
        | Por defecto, `Authenticate` construye el destino de la redireccion
        | ANTES de lanzar la excepcion, y lo hace SIEMPRE —no solo cuando la
        | peticion espera HTML—. En esta aplicacion no existe ninguna ruta
        | llamada `login` (la unica ruta web es `/`, publica; el acceso es por
        | OAuth), asi que ese calculo lanzaba RouteNotFoundException y una
        | peticion sin token acababa en **500 con traza** en vez del 401 que
        | corresponde. Con `Accept: application/json` no se notaba, porque
        | entonces la excepcion se salta el calculo: solo aparecia cuando la
        | peticion no declaraba que esperaba JSON (VUL-14).
        |
        | Devolviendo null, el destino no se calcula y la excepcion llega
        | intacta al manejador, que ya tiene `shouldRenderJsonWhen` para las
        | rutas de `api/*` de abajo y responde 401 en JSON.
        */
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
        | Normalizacion de errores no capturados (VUL-05, regla de seguridad 8).
        |
        | Los fallos previstos ya los traduce cada controlador, con su mensaje y
        | su `error_code`. Lo que se normaliza aqui es lo IMPREVISTO: la
        | excepcion que nadie atrapo. Sin esto, con APP_DEBUG activo el cliente
        | recibe la clase, el archivo, la linea y la traza entera —que es como
        | se descubrio VUL-14—, y con APP_DEBUG apagado recibe un cuerpo mudo
        | del que soporte no puede tirar.
        |
        | La respuesta lleva las tres cosas y solo esas: mensaje generico,
        | codigo estable y el identificador de correlacion con el que encontrar
        | la linea del registro. El detalle tecnico va al registro y solo alli.
        |
        | LAS QUE EL FRAMEWORK YA SABE TRADUCIR NO SE TOCAN. Autenticacion (401),
        | autorizacion (403), validacion (422), CSRF (419), registro inexistente
        | (404) y toda `HttpException` tienen ya un codigo de estado correcto y
        | un cuerpo generico. Convertirlas en 500 seria mentir sobre lo que paso
        | y, en el caso del 401, reabrir VUL-14 desde el otro lado: el primer
        | intento de este bloque hacia justo eso y tumbo 15 pruebas.
        */
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            foreach (FRAMEWORK_RENDERED_EXCEPTIONS as $alreadyHandled) {
                if ($e instanceof $alreadyHandled) {
                    return null;
                }
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $correlationId = (string) $request->attributes->get(
                AssignCorrelationId::ATTRIBUTE,
                'unassigned',
            );

            // El detalle completo al registro del servidor, atado al mismo
            // identificador que ve el usuario. La excepcion viaja entera en vez
            // de extraerle el texto: asi el registro conserva clase, traza y
            // excepcion previa, que es lo que hace falta para investigar.
            Log::error('Fallo no controlado en la API', [
                'correlation_id' => $correlationId,
                'method' => $request->getMethod(),
                // La ruta registrada, no la URL: una URL con parametros puede
                // llevar identificadores que no queremos en el registro.
                'route' => $request->route()?->uri() ?? $request->path(),
                'exception' => $e,
            ]);

            return new JsonResponse([
                // Sin clase, sin archivo, sin linea y sin traza. Ni siquiera
                // con APP_DEBUG activo: el modo de depuracion no puede ser lo
                // que separa una respuesta segura de una que no lo es.
                'message' => 'No pudimos completar la operacion. Si el problema persiste, '
                    .'comparte este identificador con soporte.',
                'error_code' => 'INTERNAL_ERROR',
                'correlation_id' => $correlationId,
            ], SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
