<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\CreditApplicationRecord;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Consulta y seguimiento de una solicitud de credito (CU-08, pantalla P7).
 *
 * Es el endpoint sobre el que se comprueban las tres capas del control de
 * acceso, que son distintas y ninguna sustituye a otra:
 *
 *   401  no hay token, o el token no vale        -> guard 'api'
 *   403  el rol no alcanza para la operacion     -> Gate sobre el permiso
 *   403  el rol alcanza pero el registro es ajeno-> politica sobre el objeto
 *   200  con el ingreso declarado omitido        -> autorizacion por campo
 */
final class CreditApplicationController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function show(Request $request, CreditApplicationRecord $creditApplication): JsonResponse
    {
        // Autorizacion a nivel de objeto: la politica recibe el registro
        // concreto, no solo el usuario. Un prospecto autenticado que pide la
        // solicitud de otro prospecto se detiene aqui (Fase 3 §4.9).
        Gate::authorize('view', $creditApplication);

        $user = $request->user();

        $payload = [
            'id' => $creditApplication->public_id,
            'folio' => $creditApplication->application_folio,
            'credit_type' => $creditApplication->credit_type,
            'application_status' => $creditApplication->application_status,
            'decided_at' => $creditApplication->decided_at?->toIso8601String(),
        ];

        // Autorizacion por campo: el ingreso declarado y la capacidad de pago
        // derivada de el solo viajan a quien tiene necesidad justificada de
        // conocerlos (RS-05). El resto recibe un 200 sin esos campos, no un
        // 403 sobre el recurso entero.
        if (Gate::allows('viewDeclaredIncome', $creditApplication)) {
            $payload['validated_monthly_income'] = $creditApplication->validated_monthly_income;
            $payload['payment_capacity'] = $creditApplication->payment_capacity;
        }

        // El motivo interno del rechazo no viaja al cliente: al prospecto se le
        // muestra un mensaje generico (riesgo R-01, regla no negociable 8).
        if ($user->hasPermission(Permission::ViewAnyCreditApplication)) {
            $payload['rejection_reason_code'] = $creditApplication->rejection_reason_code;
        }

        // La consulta de informacion personal es en si misma un evento
        // auditable (Fase 2, pantalla P7; RS-06).
        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CreditApplicationViewed,
            affectedEntity: 'CreditApplication',
            affectedEntityId: $creditApplication->id,
            prospectId: $creditApplication->prospect_id,
            context: new AuditContext(actor: $user->email, ipAddress: $request->ip()),
            eventAt: new DateTimeImmutable,
        ));

        return new JsonResponse(['data' => $payload]);
    }

    /**
     * Seguimiento de la solicitud por parte del personal autorizado.
     *
     * Existe en T5 para poder exigir por prueba que un rol de solo lectura no
     * escribe. El caso de uso completo —con el motor de reglas detras— llega en
     * su tarea; aqui solo cambia el estatus.
     */
    public function updateStatus(Request $request, CreditApplicationRecord $creditApplication): JsonResponse
    {
        Gate::authorize('updateStatus', $creditApplication);

        $validated = Validator::make($request->all(), [
            'application_status' => ['required', 'string', 'in:under_review,pre_approved,approved,rejected,expired'],
        ])->validate();

        $creditApplication->forceFill([
            'application_status' => $validated['application_status'],
        ])->save();

        return new JsonResponse(['data' => [
            'id' => $creditApplication->public_id,
            'application_status' => $creditApplication->application_status,
        ]]);
    }
}
