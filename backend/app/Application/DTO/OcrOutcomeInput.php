<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Resultado que el worker devuelve para un documento ya encolado.
 *
 * `jobRef` no es informativo: se compara contra el `ocr_job_id` guardado al
 * encolar. Un resultado que no corresponda al intento vigente se rechaza en vez
 * de aplicarse, que es lo que impide que una respuesta rezagada —o repetida—
 * pise el estado de un intento posterior.
 */
final readonly class OcrOutcomeInput
{
    /** @param array<string, mixed>|null $result */
    public function __construct(
        public string $documentPublicId,
        public string $jobRef,
        public bool $succeeded,
        public int $attempt,
        public ?array $result = null,
        public ?string $reason = null,
    ) {}
}
