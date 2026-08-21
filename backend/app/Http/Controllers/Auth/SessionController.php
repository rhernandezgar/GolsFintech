<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cierre de sesion (DELETE /api/v1/auth/session del catalogo MS-01).
 *
 * Revoca el token de acceso presentado y su refresh_token. No basta con que el
 * cliente olvide el token: un token que sigue siendo valido en el servidor es
 * un token utilizable por quien lo haya interceptado.
 */
final class SessionController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->token();

        $token->revoke();

        // Revocar solo el access_token dejaria vivo el refresh_token, con el
        // que se obtiene uno nuevo: el cierre de sesion no habria cerrado nada.
        $token->refreshToken?->revoke();

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::SessionEnded,
            affectedEntity: 'User',
            affectedEntityId: $user->id,
            prospectId: $user->prospect_id,
            context: new AuditContext(actor: $user->email, ipAddress: $request->ip()),
            eventAt: new DateTimeImmutable,
        ));

        return new JsonResponse(['message' => 'Sesion cerrada.']);
    }
}
