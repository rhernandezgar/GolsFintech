<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCase\Identity\ValidateIdentity;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\DocumentNotOwnedByProspectException;
use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Domain\Exception\ProspectDataNotConfirmedException;
use App\Domain\Identity\OverallValidationStatus;
use App\Domain\Identity\RecordedIdentityValidation;
use App\Domain\Shared\Uuid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\ValidateIdentityRequest;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * P4: endpoint que dispara la validacion de identidad contra INE y RENAPO.
 *
 * ### Tres cosas que este controlador impone y que no son obvias
 *
 * 1. **El prospecto sale del token**, siempre. La URL no acepta ningun
 *    identificador (`POST /identity-validations`, sin parametros): asi no hay
 *    forma de nombrar el expediente ajeno desde fuera. Alineado con la misma
 *    decision de P2 y de las rutas `prospects/me`.
 *
 * 2. **Al cliente no se le dice que fallo**. Cuando la validacion no sale
 *    verificada, la respuesta lleva solo `status: not_verified` y el folio de
 *    seguimiento —para soporte—: nunca `ine_status`, `renapo_status` ni
 *    ninguno de los cuatro checks individuales. Es el riesgo R-01: decirle a
 *    quien intenta suplantar una identidad si fallo INE o RENAPO le indica
 *    que corregir. La pantalla P4 del prototipo solo muestra el detalle
 *    cuando la verificacion es satisfactoria. El detalle SI viaja a la
 *    bitacora (metadata del evento `_rejected`/`_deferred`).
 *
 * 3. **Deferred no es rejected**. Cuando el proveedor devuelve "en proceso"
 *    o "no disponible", la respuesta es `status: pending` y la fila queda
 *    persistida para reintento —nunca `not_verified`—. Rechazar por caida
 *    del proveedor negaria credito a alguien con identidad valida (R-03).
 *    Si el proveedor lanza excepcion, el controlador responde 503 y el
 *    evento `_requested` ya quedo grabado antes del error.
 */
final class IdentityValidationController extends Controller
{
    public function __construct(
        private readonly ValidateIdentity $validateIdentity,
    ) {}

    public function store(ValidateIdentityRequest $request): JsonResponse
    {
        $prospect = $request->user()->prospect;

        if (! $prospect instanceof ProspectRecord) {
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a este recurso.');
        }

        $documentPublicId = $request->input('document_public_id');
        $documentUuid = ($documentPublicId === null || $documentPublicId === '')
            ? null : Uuid::fromString((string) $documentPublicId);

        try {
            $recorded = $this->validateIdentity->execute(
                Uuid::fromString($prospect->public_id),
                $documentUuid,
                new AuditContext(actor: 'prospect:'.$prospect->public_id, ipAddress: $request->ip()),
                new DateTimeImmutable,
            );
        } catch (DocumentNotOwnedByProspectException $e) {
            // Mismo mensaje que emiten las politicas: no revela si el documento
            // existe. Sin este control, un intento acertado sobre un UUID
            // ajeno sabria que ese expediente existe.
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
            ], Response::HTTP_FORBIDDEN);
        } catch (ProspectDataNotConfirmedException $e) {
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ExternalServiceUnavailableException $e) {
            // El proveedor lanzo excepcion: no hay fila que guardar, pero el
            // evento `_requested` ya quedo grabado antes de la llamada. El
            // cliente reintenta.
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['data' => $this->project($recorded)]);
    }

    /**
     * Proyeccion **asimetrica** por diseno (ver comentario de clase, punto 2).
     * Verified lleva folio; rejected y pending llevan folio y mensaje al
     * usuario. Ninguno de los tres lleva el detalle de que check fallo.
     *
     * @return array<string, string>
     */
    private function project(RecordedIdentityValidation $recorded): array
    {
        return match ($recorded->result->overallStatus()) {
            OverallValidationStatus::Verified => [
                'status' => 'verified',
                'verification_folio' => $recorded->result->verificationFolio,
                'message' => 'Identidad verificada. Ya puedes continuar.',
            ],
            OverallValidationStatus::Rejected => [
                'status' => 'not_verified',
                'verification_folio' => $recorded->result->verificationFolio,
                // Riesgo R-01: mensaje generico, sin nombrar INE ni RENAPO ni
                // decir cual de los cuatro checks fallo. Soporte lo consulta
                // con el folio.
                'message' => 'No pudimos verificar tu identidad. Acude a soporte con tu folio de verificacion.',
            ],
            OverallValidationStatus::Pending => [
                'status' => 'pending',
                'verification_folio' => $recorded->result->verificationFolio,
                'message' => 'Estamos verificando tu identidad. Vuelve a intentarlo en unos minutos.',
            ],
        };
    }
}
