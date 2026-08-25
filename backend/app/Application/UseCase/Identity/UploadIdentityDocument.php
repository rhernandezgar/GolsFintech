<?php

declare(strict_types=1);

namespace App\Application\UseCase\Identity;

use App\Application\DTO\DocumentUploadInput;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\DocumentRepository;
use App\Domain\Port\OcrService;
use App\Domain\Port\ProspectRepository;
use App\Domain\Port\TransactionManager;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * P3: se registra la identificacion ya almacenada y se encola su extraccion OCR
 * (RF-03). La extraccion es asincrona; el prospecto no espera al proveedor.
 *
 * ### El orden de este metodo es el control, no un detalle (VUL-15)
 *
 * La primera version guardaba la fila del documento, DESPUES movia el estado
 * del prospecto y solo al final anotaba en la bitacora. Cuando la transicion
 * de estado lanzaba —y lanzaba, en toda la rama manual— quedaba una fila
 * persistida, un archivo en disco y **ningun evento**: la bitacora no
 * registraba una carga que si habia ocurrido. El 500 era lo visible; el hueco
 * de trazabilidad era lo grave.
 *
 * Ahora el metodo se ordena en tres tramos, y la separacion es deliberada:
 *
 *   1. **Lo que puede fallar sin escribir nada.** Resolver el prospecto y
 *      mover su estado. Son operaciones de memoria: si revientan, no hay nada
 *      que deshacer porque no se ha escrito nada.
 *   2. **Lo que se escribe junto o no se escribe.** Documento, prospecto y
 *      evento de bitacora, dentro de una sola transaccion. Un fallo en
 *      cualquiera de los tres deshace los otros dos, de modo que no puede
 *      volver a quedar una fila sin su evento.
 *   3. **Lo que sale del sistema.** Encolar la extraccion, que habla con
 *      Redis. Va FUERA de la transaccion a proposito: sostenerla abierta
 *      durante la latencia de una red seria peor que el problema que resuelve,
 *      y es la misma asimetria que ya aplica `EloquentCustomerRegistry` con el
 *      emisor de tarjetas.
 *
 * El tramo 3 tiene su propia consecuencia asumida: si el encolado falla, el
 * documento queda registrado y auditado pero sin trabajo en cola. Es
 * recuperable —la fila conserva ruta y hash, y se puede reencolar sin pedirle
 * nada al prospecto (PT-03)— y el controlador lo traduce a 503.
 */
final readonly class UploadIdentityDocument
{
    public function __construct(
        private ProspectRepository $prospects,
        private DocumentRepository $documents,
        private OcrService $ocrService,
        private AuditLogger $auditLogger,
        private TransactionManager $transactions,
    ) {}

    public function execute(
        DocumentUploadInput $input,
        AuditContext $context,
        DateTimeImmutable $now,
    ): IdentityDocument {
        $prospect = $this->prospects->findByPublicId(Uuid::fromString($input->prospectPublicId));

        if ($prospect === null || $prospect->id() === null) {
            throw new RuntimeException('El prospecto indicado no existe.');
        }

        $document = IdentityDocument::register(
            prospectId: $prospect->id(),
            documentType: DocumentType::from($input->documentType),
            storagePath: $input->storagePath,
            detectedMimeType: $input->detectedMimeType,
            fileSizeBytes: $input->fileSizeBytes,
            fileHash: $input->fileHash,
            originalExtension: $input->originalExtension,
        );

        // Tramo 1: en memoria. Si esto lanza, no hay nada escrito que deshacer.
        // Con los datos ya confirmados es un no-op deliberado y el estado no
        // retrocede (VUL-15); el motivo esta en `Prospect::markDocumentUploaded`.
        $prospect->markDocumentUploaded();

        // Tramo 2: las tres escrituras, juntas o ninguna.
        $document = $this->transactions->transactional(
            function () use ($document, $prospect, $context, $now): IdentityDocument {
                $document = $this->documents->save($document);
                $this->prospects->save($prospect);

                $this->auditLogger->append(new AuditEvent(
                    eventType: AuditEventType::DocumentUploaded,
                    affectedEntity: 'IdentityDocument',
                    affectedEntityId: $document->id(),
                    prospectId: $prospect->id(),
                    context: $context,
                    eventAt: $now,
                    metadata: [
                        'document_type' => $document->documentType()->value,
                        'detected_mime_type' => $document->detectedMimeType(),
                        'file_size_bytes' => $document->fileSizeBytes(),
                        'file_hash' => $document->fileHash(),
                    ],
                ));

                return $document;
            }
        );

        // Tramo 3: fuera de la transaccion. Habla con Redis.
        $jobId = $this->ocrService->enqueueExtraction($document);
        $document->queueForExtraction($jobId);
        $document = $this->documents->save($document);

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::DocumentOcrQueued,
            affectedEntity: 'IdentityDocument',
            affectedEntityId: $document->id(),
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: ['ocr_job_id' => $jobId, 'attempt' => $document->ocrAttempts()],
        ));

        return $document;
    }
}
