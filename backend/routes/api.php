<?php

declare(strict_types=1);

use App\Domain\Access\Permission;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CreditApplicationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| API v1
|-------------------------------------------------------------------------------
|
| Los endpoints del servidor OAuth2 —/auth/authorize y /auth/token— no se
| declaran aqui: los publica Passport bajo el mismo prefijo, por
| config('passport.path'), con el middleware EnforcePkceS256 aplicado al grupo.
|
| Regla de seguridad no negociable 9: autenticado por defecto. Las excepciones
| se declaran de forma explicita y son solo dos, ambas por necesidad —quien
| todavia no tiene token no puede presentarlo—:
|
|   POST /auth/login    limitado por intentos, mensaje generico
|   POST /auth/refresh  el refresh_token es la credencial
|
*/

Route::middleware('web')->group(function (): void {
    // El acceso inicial se apoya en la sesion del guard web, que es la que
    // consulta despues el servidor de autorizacion para saber quien concede.
    Route::post('/auth/login', [LoginController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.login');
});

Route::post('/auth/refresh', [RefreshTokenController::class, 'store'])
    ->middleware('throttle:12,1')
    ->name('auth.refresh');

Route::middleware('auth:api')->group(function (): void {
    // Gestion de la propia sesion. Queda fuera del grupo de recursos porque
    // cerrar sesion no es un cambio de estado del negocio: un rol de solo
    // lectura tiene que poder hacerlo.
    Route::delete('/auth/session', [SessionController::class, 'destroy'])
        ->name('auth.session.destroy');

    Route::get('/me', [MeController::class, 'show'])->name('me');

    // Recursos de negocio. read-only se aplica al grupo entero y no ruta por
    // ruta: que un rol de solo lectura no escriba es una propiedad del rol, y
    // una ruta nueva que olvidara declararlo naceria desprotegida.
    Route::middleware('read-only')->group(function (): void {
        Route::get('/credit-applications/{creditApplication}', [CreditApplicationController::class, 'show'])
            ->name('credit-applications.show');

        // El filtro por rol es grueso y no basta: la politica vuelve a decidir
        // sobre el registro concreto dentro del controlador.
        Route::patch('/credit-applications/{creditApplication}/status', [CreditApplicationController::class, 'updateStatus'])
            ->middleware('role:admin')
            ->name('credit-applications.update-status');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('can:'.Permission::ReadAuditLog->value)
            ->name('audit-logs.index');
    });
});
