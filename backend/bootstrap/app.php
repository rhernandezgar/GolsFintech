<?php

use App\Http\Middleware\EnforceReadOnlyRole;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\EnsureClientIsResourceOwner;

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
    })->create();
