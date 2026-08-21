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
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * P3: se registra la identificacion ya almacenada y se encola su extraccion OCR
 * (RF-03). La extraccion es asincrona; el prospecto no espera al proveedor.
 */
final readonly class UploadIdentityDocument
{
    public function __construct(
        private ProspectRepository $prospects,
        private DocumentRepository $documents,
        private OcrService $ocrService,
        private AuditLogger $auditLogger,
    ) {
    }

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

        $document = $this->documents->save($document);

        $prospect->markDocumentUploaded();
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
