<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Resultado de recorrer la cadena entera.
 *
 * Guarda el hash de la punta ademas de las rupturas: es el unico dato con el que se
 * detecta el truncado del final de la bitacora, que ninguna comprobacion interna
 * puede ver (si se borran los ultimos registros, lo que queda sigue siendo una
 * cadena perfectamente coherente).
 */
final readonly class ChainVerificationResult
{
    /** @param list<ChainBreak> $breaks */
    public function __construct(
        public int $verifiedRecords,
        public array $breaks,
        public ?string $tipHash,
        public ?int $tipRecordId,
    ) {}

    public function isIntact(): bool
    {
        return $this->breaks === [];
    }

    public function firstBreak(): ?ChainBreak
    {
        return $this->breaks[0] ?? null;
    }
}
