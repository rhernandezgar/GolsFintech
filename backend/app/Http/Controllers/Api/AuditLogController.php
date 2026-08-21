<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lectura de la bitacora de auditoria (CU-09, pantalla P7).
 *
 * Solo lectura, y no por convencion: la bitacora es append-only y el catalogo
 * de permisos no define ninguno de escritura sobre ella.
 */
final class AuditLogController extends Controller
{
    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Paginacion del lado del servidor con tope duro: sin el, un
        // per_page=1000000 extrae la bitacora entera manipulando un parametro
        // (Fase 2, pantalla P7).
        $perPage = min((int) $request->integer('per_page', 25) ?: 25, self::MAX_PER_PAGE);

        $logs = AuditLogRecord::query()
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::AuditLogViewed,
            affectedEntity: 'AuditLog',
            affectedEntityId: null,
            prospectId: null,
            context: new AuditContext(actor: $user->email, ipAddress: $request->ip()),
            eventAt: new DateTimeImmutable,
        ));

        return new JsonResponse([
            'data' => $logs->map(static fn (AuditLogRecord $log): array => [
                'event_type' => $log->event_type,
                'affected_entity' => $log->affected_entity,
                'affected_entity_id' => $log->affected_entity_id,
                'actor' => $log->actor,
                'ip_address' => $log->ip_address,
                'event_at' => $log->event_at,
                // El encadenamiento se publica para que un auditor externo
                // pueda recalcularlo, no como dato de negocio.
                'previous_hash' => $log->previous_hash,
                'current_hash' => $log->current_hash,
            ])->all(),
            'meta' => [
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
