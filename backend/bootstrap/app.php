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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
