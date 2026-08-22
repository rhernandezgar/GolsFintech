<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Una ruptura concreta, con su ubicacion.
 *
 * "La cadena es invalida" no sirve para responder a un incidente: hay que poder
 * decir en que registro se rompe, de que evento y de que actor se trata, y que
 * valor se esperaba frente a cual hay escrito.
 */
final readonly class ChainBreak
{
    public function __construct(
        public ChainBreakKind $kind,
        public int $recordId,
        /** Registro que precede a recordId en la tabla; null si recordId es el primero. */
        public ?int $previousRecordId,
        public string $eventType,
        public string $actor,
        public string $eventAt,
        public ?string $expected,
        public ?string $stored,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'record_id' => $this->recordId,
            'previous_record_id' => $this->previousRecordId,
            'event_type' => $this->eventType,
            'actor' => $this->actor,
            'event_at' => $this->eventAt,
            'expected' => $this->expected,
            'stored' => $this->stored,
        ];
    }
}
