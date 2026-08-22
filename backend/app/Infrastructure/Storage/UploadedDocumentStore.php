<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\DTO\DocumentUploadInput;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Guarda la identificacion oficial cargada y devuelve sus metadatos (VUL-01).
 *
 * Tres controles que no son intercambiables:
 *
 * 1. **Tipo real por inspeccion del contenido.** La extension y el
 *    `Content-Type` los elige quien sube el archivo; ninguno de los dos es
 *    evidencia. Aqui manda `finfo`, que lee los primeros bytes. Un ejecutable
 *    renombrado a `.jpg` no pasa.
 * 2. **Nombre generado, nunca el del cliente.** El nombre original puede
 *    contener `../`, bytes nulos o una segunda extension. No se usa para nada
 *    salvo conservar la extension declarada como dato informativo.
 * 3. **Fuera de la raiz web.** El disco `local` apunta a storage/app/private,
 *    que ningun servidor sirve directamente.
 *
 * El hash SHA-256 se calcula aqui, al cargar, y la entidad lo vuelve a
 * comprobar antes de cada procesamiento: asi la imagen que llega al OCR es
 * demostrablemente la que subio el prospecto (RS-02).
 */
final readonly class UploadedDocumentStore
{
    /** Tipos que el proveedor de OCR puede leer. */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private FilesystemFactory $filesystem,
        private string $disk = 'local',
        private string $directory = 'identity-documents',
        /** 5 MB: limite de la Fase 2 §P3, contra el agotamiento de recursos. */
        private int $maxSizeBytes = 5_242_880,
    ) {}

    public function store(UploadedFile $file, string $prospectPublicId, string $documentType): DocumentUploadInput
    {
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_readable($realPath)) {
            throw new DocumentUploadRejected('El archivo no se pudo leer.');
        }

        $sizeBytes = (int) filesize($realPath);

        if ($sizeBytes <= 0) {
            throw new DocumentUploadRejected('El archivo esta vacio.');
        }

        if ($sizeBytes > $this->maxSizeBytes) {
            throw new DocumentUploadRejected('El archivo supera el tamano maximo permitido.');
        }

        // El tipo REAL, no el declarado.
        $detectedMimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);

        if (! array_key_exists($detectedMimeType, self::ALLOWED_MIME_TYPES)) {
            // El mensaje no dice cual se detecto: no hace falta confirmarle a
            // quien prueba formatos que su ejecutable se identifico como tal.
            throw new DocumentUploadRejected('El tipo de archivo no esta permitido.');
        }

        $fileHash = hash_file('sha256', $realPath);

        if ($fileHash === false) {
            throw new DocumentUploadRejected('El archivo no se pudo procesar.');
        }

        // Nombre generado. La extension sale del tipo detectado, no del nombre
        // que mando el cliente.
        $storagePath = sprintf(
            '%s/%s/%s.%s',
            $this->directory,
            $prospectPublicId,
            Str::uuid()->toString(),
            self::ALLOWED_MIME_TYPES[$detectedMimeType],
        );

        $this->filesystem->disk($this->disk)->put($storagePath, file_get_contents($realPath));

        return new DocumentUploadInput(
            prospectPublicId: $prospectPublicId,
            documentType: $documentType,
            storagePath: $storagePath,
            detectedMimeType: $detectedMimeType,
            fileSizeBytes: $sizeBytes,
            fileHash: $fileHash,
            // Se conserva solo como dato informativo; nunca se usa para
            // construir una ruta ni para decidir el tipo.
            originalExtension: $this->safeExtension($file),
        );
    }

    private function safeExtension(UploadedFile $file): ?string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : null;
    }
}
