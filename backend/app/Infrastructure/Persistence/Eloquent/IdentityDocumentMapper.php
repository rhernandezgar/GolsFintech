<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Identity\DocumentType;
use App\Domain\Identity\IdentityDocument;
use App\Domain\Identity\OcrStatus;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/** Traduce entre IdentityDocument y la fila de identity_documents. */
final readonly class IdentityDocumentMapper
{
    /** @return array<string, mixed> */
    public function toAttributes(IdentityDocument $document): array
    {
        return [
            'public_id' => $document->publicId()->value,
            'prospect_id' => $document->prospectId(),
            'document_type' => $document->documentType()->value,
            'storage_path' => $document->storagePath(),
            'original_extension' => $document->originalExtension(),
            'detected_mime_type' => $document->detectedMimeType(),
            'file_size_bytes' => $document->fileSizeBytes(),
            'file_hash' => $document->fileHash(),
            'ocr_status' => $document->ocrStatus()->value,
            'ocr_result' => $document->ocrResult(),
            'ocr_attempts' => $document->ocrAttempts(),
            'ocr_job_id' => $document->ocrJobId(),
            'processed_at' => $document->processedAt(),
        ];
    }

    public function toDomain(IdentityDocumentRecord $record): IdentityDocument
    {
        $processedAt = $record->processed_at;

        return IdentityDocument::reconstitute(
            id: (int) $record->id,
            publicId: Uuid::fromString((string) $record->public_id),
            prospectId: (int) $record->prospect_id,
            documentType: DocumentType::from((string) $record->document_type),
            storagePath: (string) $record->storage_path,
            originalExtension: $record->original_extension,
            detectedMimeType: (string) $record->detected_mime_type,
            fileSizeBytes: (int) $record->file_size_bytes,
            fileHash: (string) $record->file_hash,
            ocrStatus: OcrStatus::from((string) $record->ocr_status),
            ocrResult: $record->ocr_result,
            ocrAttempts: (int) $record->ocr_attempts,
            ocrJobId: $record->ocr_job_id,
            processedAt: $processedAt === null
                ? null
                : new DateTimeImmutable($processedAt->format('Y-m-d H:i:s')),
        );
    }
}
