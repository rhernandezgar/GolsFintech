<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCase\Identity\UploadIdentityDocument;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\ExternalServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\UploadIdentityDocumentRequest;
use App\Infrastructure\Persistence\Eloquent\IdentityDocumentRecord;
use App\Infrastructure\Storage\DocumentUploadRejected;
use App\Infrastructure\Storage\UploadedDocumentStore;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Carga de la identificacion oficial y seguimiento de su extraccion (P3, RF-03).
 *
 * POR QUE 202 Y NO 200. La extraccion la hace un worker aparte y puede tardar
 * segundos o reintentarse tres veces: si este endpoint esperara al OCR,
 * mantendria ocupado un proceso de PHP-FPM por cada carga y una racha de subidas
 * agotaria el pool (Fase 2, riesgo R-03). 202 Accepted es la respuesta honesta:
 * «lo recibi, todavia no esta hecho», y el identificador de seguimiento es como
 * el cliente pregunta despues (Fase 2, Figura 2a).
 *
 * QUIEN PUEDE SUBIR. El prospecto se toma SIEMPRE del usuario autenticado, nunca
 * de un campo de la peticion. No es un detalle de comodidad: aceptar un
 * `prospect_id` del cliente es la forma clasica de IDOR (CWE-639), y aqui no hay
 * nada que autorizar por objeto porque no hay objeto ajeno que nombrar.
 */
final class IdentityDocumentController extends Controller
{
    public function __construct(
        private readonly UploadIdentityDocument $useCase,
        private readonly UploadedDocumentStore $store,
        private readonly LoggerInterface $logger,
    ) {}

    public function store(UploadIdentityDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $prospect = $user->prospect;

        if ($prospect === null) {
            return new JsonResponse(
                ['message' => 'Tu cuenta no tiene una solicitud en curso.'],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $input = $this->store->store(
                file: $request->file('document'),
                prospectPublicId: $prospect->public_id,
                documentType: $request->string('document_type')->toString(),
            );
        } catch (DocumentUploadRejected $e) {
            // El mensaje ya nace generico en el almacen; no se anade detalle.
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $context = new AuditContext(actor: $user->email, ipAddress: $request->ip());

        try {
            $document = $this->useCase->execute($input, $context, new DateTimeImmutable);
        } catch (ExternalServiceUnavailableException $e) {
            // La cola no acepta trabajos. El archivo ya esta guardado y el
            // prospecto conserva sus datos: se puede reintentar la carga sin
            // volver a capturar nada.
            $this->logger->error('No se pudo encolar la extraccion del documento', [
                'prospect_public_id' => $prospect->public_id,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['message' => 'El servicio no esta disponible en este momento. Intentalo de nuevo en unos minutos.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        // 202 Accepted: encolado, no procesado. El cuerpo lleva con que
        // preguntar despues y donde.
        return new JsonResponse([
            'data' => [
                'tracking_id' => $document->publicId()->value,
                'ocr_status' => $document->ocrStatus()->value,
                'attempt' => $document->ocrAttempts(),
                'status_url' => route('identity-documents.show', ['identityDocument' => $document->publicId()->value]),
            ],
            'message' => 'Recibimos tu identificacion. La estamos procesando.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Seguimiento de la extraccion. Es lo que el cliente consulta con el
     * identificador que le devolvio el 202.
     */
    public function show(Request $request, string $identityDocument): JsonResponse
    {
        $user = $request->user();

        $record = IdentityDocumentRecord::query()
            ->where('public_id', $identityDocument)
            ->first();

        // Autorizacion por objeto: un prospecto autenticado no puede seguir el
        // documento de otro. Se responde 404 y no 403 a proposito: distinguirlos
        // le confirmaria a quien prueba identificadores cuales existen.
        if ($record === null || $record->prospect_id !== $user->prospect_id) {
            return new JsonResponse(['message' => 'No encontramos ese documento.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => [
            'tracking_id' => $record->public_id,
            'ocr_status' => $record->ocr_status,
            'attempt' => $record->ocr_attempts,
            'processed_at' => $record->processed_at?->toIso8601String(),
            // El resultado del OCR no se devuelve aqui: son datos personales
            // extraidos del documento y viajan en la pantalla de validacion (P4)
            // por su propio endpoint.
        ]]);
    }
}
