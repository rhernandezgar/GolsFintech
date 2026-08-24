<?php

declare(strict_types=1);

use App\Domain\Access\Permission;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CreditApplicationController;
use App\Http\Controllers\Api\IdentityDocumentController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ProspectController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Internal\OcrResultController;
use App\Infrastructure\Security\ProspectSessionIssuer;
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
| se declaran de forma explicita y son por necesidad —quien todavia no tiene
| token no puede presentarlo—:
|
|   POST /auth/login     limitado por intentos, mensaje generico
|   POST /auth/refresh   el refresh_token es la credencial
|   POST /prospects      QUINTA EXCEPCION, vease abajo
|
| ---------------------------------------------------------------------------
| La quinta excepcion: POST /prospects (P1)
| ---------------------------------------------------------------------------
|
| Es el endpoint donde NACE la credencial del prospecto. Quien llega a P1 no
| tiene cuenta ni la va a crear: el prototipo de la Fase 2 no contempla ninguna
| pantalla de registro y la Fase 1 estima el tramite completo en menos de cinco
| minutos (RNF-02). Exigir token aqui seria pedirle al visitante que presente
| algo que solo esta peticion puede darle.
|
| La alternativa que se descarto era dejar publicas las seis pantallas del
| portal y pasar el identificador del expediente por la URL. Habria multiplicado
| las excepciones por seis y, peor, habria convertido ese identificador en una
| credencial de portador: quien lo adivinara o lo interceptara leeria y
| modificaria la solicitud ajena (CWE-639). Con una sola excepcion, el prospecto
| sale del token en todos los pasos siguientes y no hay identificador ajeno que
| nombrar.
|
| Lo que compensa la exposicion, porque una excepcion no se declara y ya:
|
|   - throttle:5,1        limita la creacion por direccion IP;
|   - CAPTCHA obligatorio  encarece repartir el trabajo entre muchas
|                          direcciones, que es justo lo que el throttle no ve
|                          (RS-10, riesgo R-05). El control esta en el
|                          controlador, no en el FormRequest, para poder
|                          registrar el rechazo en la bitacora;
|   - el token que emite  nace acotado al scope `prospect-session` y con 30
|                          minutos de vigencia;
|   - no devuelve nada    ni confirma ni niega la existencia de ningun
|                          expediente anterior: solo crea el suyo.
|
*/

/*
| P1. Inicio de la solicitud: crea el expediente y emite la sesion del
| prospecto. Quinta excepcion a la regla 9, justificada en la cabecera.
*/
Route::post('/prospects', [ProspectController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('prospects.store');

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

    /*
    | Recorrido del prospecto (P2 a P6). El expediente sale SIEMPRE del token,
    | nunca de la URL: no hay ningun identificador de otro prospecto que se
    | pueda nombrar desde fuera.
    |
    | El scope acota lo que el token puede hacer con independencia del rol del
    | usuario. Es defensa en profundidad: si manana un fallo escalara el rol,
    | el token seguiria sin abrir nada que no estuviera en su alcance el dia que
    | se emitio.
    */
    Route::middleware('scopes:'.ProspectSessionIssuer::PROSPECT_SCOPE)
        ->prefix('prospects/me')
        ->group(function (): void {
            Route::get('/', [ProspectController::class, 'show'])->name('prospects.me.show');

            // P2. Captura parcial: el prospecto llena el formulario por pasos
            // y no siempre envia todos los campos. La comprobacion de
            // expediente completo es cosa de `confirm`, no de este endpoint.
            Route::patch('/', [ProspectController::class, 'update'])
                ->name('prospects.me.update');

            // P2 (paso 2). Confirmacion: exige el expediente completo.
            // Responde 422 con `missing_fields` si algo falta. Es la
            // transicion que habilita P4.
            Route::post('/confirm', [ProspectController::class, 'confirm'])
                ->name('prospects.me.confirm');

            // Renovacion silenciosa mientras haya actividad.
            Route::post('/session', [ProspectController::class, 'renewSession'])
                ->middleware('throttle:20,1')
                ->name('prospects.me.session.renew');
        });

    // Recursos de negocio. read-only se aplica al grupo entero y no ruta por
    // ruta: que un rol de solo lectura no escriba es una propiedad del rol, y
    // una ruta nueva que olvidara declararlo naceria desprotegida.
    Route::middleware('read-only')->group(function (): void {
        // P3. La carga responde 202 Accepted en cuanto encola: no espera al OCR
        // (Fase 2, Figura 2a). El identificador que devuelve es con el que se
        // consulta despues el estado en la ruta de abajo.
        Route::post('/identity-documents', [IdentityDocumentController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('identity-documents.store');

        Route::get('/identity-documents/{identityDocument}', [IdentityDocumentController::class, 'show'])
            ->name('identity-documents.show');

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

/*
| Ruta interna: la llama el worker, no una persona.
|
| No entra en el grupo de `auth:api` porque su credencial es otra: un token de
| client_credentials con el scope 'ocr-result', que es lo que comprueba el
| middleware `client`. Sigue siendo autenticada (regla 9); lo que cambia es
| quien se autentica.
*/
Route::middleware('client:ocr-result')->prefix('internal')->group(function (): void {
    Route::post('/ocr-results', [OcrResultController::class, 'store'])
        ->name('internal.ocr-results.store');
});
