<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Metadatos del archivo YA almacenado por el adaptador de carga: la ruta fuera de
 * la raiz web, el tipo real detectado por inspeccion de contenido y el hash
 * SHA-256 del contenido (VUL-01). El caso de uso no toca el sistema de archivos.
 */
final readonly class DocumentUploadInput
{
    public function __construct(
        public string $prospectPublicId,
        public string $documentType,
        public string $storagePath,
        public string $detectedMimeType,
        public int $fileSizeBytes,
        public string $fileHash,
        public ?string $originalExtension = null,
    ) {}
}
