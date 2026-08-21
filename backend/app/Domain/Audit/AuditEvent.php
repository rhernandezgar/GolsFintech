<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use DateTimeImmutable;

/**
 * Evento de la bitacora append-only (audit_logs).
 *
 * Usa referencia polimorfica, no una llave foranea por entidad: prospect_id ancla
 * todo el ciclo de vida —incluso despues de que el prospecto se vuelve cliente— y
 * affected_entity + affected_entity_id apuntan a la entidad concreta que cambio
 * (CLAUDE.md seccion 5).
 *
 * Los metadatos se enmascaran EN EL CONSTRUCTOR: no existe forma de construir un
 * evento que conserve una CURP, un RFC o un PAN en claro.
 */
final readonly class AuditEvent
{
    /** @var array<array-key, mixed> */
    public array $metadata;

    /** @param array<array-key, mixed> $metadata */
    public function __construct(
        public AuditEventType $eventType,
        public string $affectedEntity,
        public ?int $affectedEntityId,
        public ?int $prospectId,
        public AuditContext $context,
        public DateTimeImmutable $eventAt,
        array $metadata = [],
        ?SensitiveDataMasker $masker = null,
    ) {
        $this->metadata = ($masker ?? new SensitiveDataMasker)->mask($metadata);
    }

    public function actor(): string
    {
        return $this->context->actor;
    }

    public function ipAddress(): ?string
    {
        return $this->context->ipAddress;
    }
}
