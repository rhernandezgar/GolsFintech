<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Application\DTO\OcrOutcomeInput;
use App\Application\UseCase\Identity\RecordOcrOutcome;
use App\Domain\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\RecordOcrOutcomeRequest;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Recibe del worker el resultado de la extraccion. Cierra el ciclo del 202.
 *
 * QUIEN LLAMA AQUI ES UN PROCESO, no una persona: la ruta va detras del
 * middleware `client` de Passport, que exige un token de client_credentials con
 * el scope 'ocr-result'. Por eso no hay usuario del que sacar el actor de la
 * bitacora y se registra como 'worker'.
 *
 * La direccion de la llamada es deliberada: el worker llama al backend y nunca al
 * reves. Un puerto abierto en el worker seria una segunda superficie de ataque,
 * sin la autenticacion ni la auditoria que tiene esta API.
 */
final class OcrResultController extends Controller
{
    public function __construct(
        private readonly RecordOcrOutcome $useCase,
        private readonly LoggerInterface $logger,
    ) {}

    public function store(RecordOcrOutcomeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $input = new OcrOutcomeInput(
            documentPublicId: $validated['document_public_id'],
            jobRef: $validated['job_ref'],
            succeeded: $validated['status'] === 'extracted',
            attempt: $validated['attempt'],
            result: $validated['result'] ?? null,
            reason: $validated['reason'] ?? null,
        );

        try {
            $document = $this->useCase->execute(
                $input,
                new AuditContext(actor: 'worker', ipAddress: $request->ip()),
                new DateTimeImmutable,
            );
        } catch (RuntimeException $e) {
            // Documento inexistente o resultado que no corresponde al intento
            // vigente. El detalle va al registro del servidor; al llamante solo
            // el codigo (regla 8).
            $this->logger->warning('Resultado de OCR rechazado', [
                'document_public_id' => $input->documentPublicId,
                'job_ref' => $input->jobRef,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse(['message' => 'El resultado no se pudo aplicar.'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['data' => [
            'tracking_id' => $document->publicId()->value,
            'ocr_status' => $document->ocrStatus()->value,
        ]]);
    }
}
