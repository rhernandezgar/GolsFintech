<?php

declare(strict_types=1);

namespace App\Application\UseCase\Identity;

use App\Application\DTO\OcrOutcomeInput;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\DocumentRepository;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * Aplica al documento el resultado que devuelve el worker (RF-03, Figura 2a).
 *
 * Es el otro extremo del tramo asincrono: `UploadIdentityDocument` encola y
 * responde 202; esto cierra el ciclo cuando el worker termina.
 *
 * Tres cosas que no son opcionales:
 *
 * 1. **Correlacion.** El `job_ref` recibido tiene que ser el del intento vigente.
 *    Un resultado rezagado de un intento anterior no puede pisar el estado
 *    actual del documento.
 * 2. **Idempotencia.** Si el documento ya esta en un estado final, se devuelve
 *    tal cual sin volver a escribir. El worker puede reintentar el aviso —una
 *    respuesta perdida, un 500 pasajero— y repetirlo no debe duplicar eventos en
 *    la bitacora.
 * 3. **PT-03.** El fracaso NO borra nada. `failExtraction()` marca el estado y la
 *    fecha; la ruta del archivo, su hash y los datos del prospecto siguen donde
 *    estaban, asi que la solicitud se puede recuperar sin volver a pedirle el
 *    documento al prospecto.
 */
final readonly class RecordOcrOutcome
{
    public function __construct(
        private DocumentRepository $documents,
        private AuditLogger $auditLogger,
    ) {}

    public function execute(
        OcrOutcomeInput $input,
        AuditContext $context,
        DateTimeImmutable $now,
    ): IdentityDocument {
        $document = $this->documents->findByPublicId(Uuid::fromString($input->documentPublicId));

        if ($document === null) {
            throw new RuntimeException('El documento indicado no existe.');
        }

        if ($document->ocrJobId() !== $input->jobRef) {
            throw new RuntimeException('El resultado no corresponde al intento vigente del documento.');
        }

        // Idempotente: repetir el aviso no vuelve a escribir ni duplica eventos.
        if ($document->ocrStatus()->isFinal()) {
            return $document;
        }

        if ($input->succeeded) {
            $document->completeExtraction($input->result ?? [], $now);
        } else {
            $document->failExtraction($now);
        }

        $document = $this->documents->save($document);

        $this->auditLogger->append(new AuditEvent(
            eventType: $input->succeeded
                ? AuditEventType::DocumentOcrCompleted
                : AuditEventType::DocumentOcrFailed,
            affectedEntity: 'IdentityDocument',
            affectedEntityId: $document->id(),
            prospectId: $document->prospectId(),
            context: $context,
            eventAt: $now,
            metadata: [
                'ocr_job_id' => $input->jobRef,
                'attempt' => $input->attempt,
                // El motivo es un codigo del worker ('unreadable', 'exhausted'),
                // no texto libre del proveedor: la bitacora no es sitio para
                // volcar respuestas ajenas.
                'reason' => $input->reason,
                // Que el trabajo siga en la cola es parte del hallazgo: es lo
                // que permite reencolar sin pedirle nada al prospecto (PT-03).
                'recoverable' => ! $input->succeeded,
            ],
        ));

        return $document;
    }
}
