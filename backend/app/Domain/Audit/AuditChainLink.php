<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use DateTimeImmutable;

/**
 * Un eslabon ya escrito de la bitacora, tal y como esta almacenado.
 *
 * No es un AuditEvent: es la fila. La verificacion no reconstruye el evento de
 * dominio —eso volveria a pasar los metadatos por el enmascarador y a normalizar la
 * fecha, ocultando justo las diferencias que se buscan— sino que recalcula el hash
 * sobre lo que hay guardado y lo compara con lo que se guardo.
 */
final readonly class AuditChainLink
{
    /** @param array<array-key, mixed> $metadata */
    public function __construct(
        public int $id,
        public ?int $prospectId,
        public string $affectedEntity,
        public ?int $affectedEntityId,
        public string $eventType,
        public string $actor,
        public ?string $ipAddress,
        public DateTimeImmutable $eventAt,
        public array $metadata,
        public ?string $previousHash,
        public string $currentHash,
    ) {}
}
