<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/**
 * Identificacion oficial cargada por el prospecto (P3).
 *
 * La entidad no conoce el archivo: solo su referencia de almacenamiento y su hash
 * SHA-256. El hash se calcula al cargar y se vuelve a comprobar antes de cada
 * procesamiento, de modo que la imagen que llega al OCR es demostrablemente la que
 * subio el prospecto y no otra sustituida despues (integridad, VUL-01).
 */
final class IdentityDocument
{
    private const MAX_OCR_ATTEMPTS = 3;

    private function __construct(
        private ?int $id,
        private readonly Uuid $publicId,
        private readonly int $prospectId,
        private readonly DocumentType $documentType,
        private readonly string $storagePath,
        private readonly ?string $originalExtension,
        private readonly string $detectedMimeType,
        private readonly int $fileSizeBytes,
        private readonly string $fileHash,
        private OcrStatus $ocrStatus,
        private ?array $ocrResult,
        private int $ocrAttempts,
        private ?string $ocrJobId,
        private ?DateTimeImmutable $processedAt,
    ) {
    }

    public static function register(
        int $prospectId,
        DocumentType $documentType,
        string $storagePath,
        string $detectedMimeType,
        int $fileSizeBytes,
        string $fileHash,
        ?string $originalExtension = null,
    ): self {
        return new self(
            id: null,
            publicId: Uuid::generate(),
            prospectId: $prospectId,
            documentType: $documentType,
            storagePath: $storagePath,
            originalExtension: $originalExtension,
            detectedMimeType: $detectedMimeType,
            fileSizeBytes: $fileSizeBytes,
            fileHash: $fileHash,
            ocrStatus: OcrStatus::Pending,
            ocrResult: null,
            ocrAttempts: 0,
            ocrJobId: null,
            processedAt: null,
        );
    }

    /** @param array<string, mixed>|null $ocrResult */
    public static function reconstitute(
        int $id,
        Uuid $publicId,
        int $prospectId,
        DocumentType $documentType,
        string $storagePath,
        ?string $originalExtension,
        string $detectedMimeType,
        int $fileSizeBytes,
        string $fileHash,
        OcrStatus $ocrStatus,
        ?array $ocrResult,
        int $ocrAttempts,
        ?string $ocrJobId,
        ?DateTimeImmutable $processedAt,
    ): self {
        return new self($id, $publicId, $prospectId, $documentType, $storagePath, $originalExtension,
            $detectedMimeType, $fileSizeBytes, $fileHash, $ocrStatus, $ocrResult, $ocrAttempts,
            $ocrJobId, $processedAt);
    }

    public function queueForExtraction(string $jobId): void
    {
        if ($this->ocrStatus->isFinal()) {
            throw new InvalidStateTransitionException('El documento ya fue procesado por el OCR.');
        }

        $this->ocrStatus = OcrStatus::Processing;
        $this->ocrJobId = $jobId;
        $this->ocrAttempts++;
    }

    /** @param array<string, mixed> $result */
    public function completeExtraction(array $result, DateTimeImmutable $processedAt): void
    {
        $this->ocrStatus = OcrStatus::Completed;
        $this->ocrResult = $result;
        $this->processedAt = $processedAt;
    }

    public function failExtraction(DateTimeImmutable $failedAt): void
    {
        $this->ocrStatus = OcrStatus::Failed;
        $this->processedAt = $failedAt;
    }

    public function canRetryExtraction(): bool
    {
        return $this->ocrAttempts < self::MAX_OCR_ATTEMPTS;
    }

    /** Comprueba que el archivo leido sigue siendo el que se cargo. */
    public function matchesFileHash(string $hash): bool
    {
        return hash_equals($this->fileHash, $hash);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function publicId(): Uuid
    {
        return $this->publicId;
    }

    public function prospectId(): int
    {
        return $this->prospectId;
    }

    public function documentType(): DocumentType
    {
        return $this->documentType;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function originalExtension(): ?string
    {
        return $this->originalExtension;
    }

    public function detectedMimeType(): string
    {
        return $this->detectedMimeType;
    }

    public function fileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    public function fileHash(): string
    {
        return $this->fileHash;
    }

    public function ocrStatus(): OcrStatus
    {
        return $this->ocrStatus;
    }

    /** @return array<string, mixed>|null */
    public function ocrResult(): ?array
    {
        return $this->ocrResult;
    }

    public function ocrAttempts(): int
    {
        return $this->ocrAttempts;
    }

    public function ocrJobId(): ?string
    {
        return $this->ocrJobId;
    }

    public function processedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }
}
