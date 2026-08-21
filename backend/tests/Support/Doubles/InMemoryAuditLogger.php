<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Audit\AuditEvent;
use App\Domain\Port\AuditLogger;

/**
 * Doble en memoria de la bitacora. Guarda los eventos en orden para que una prueba
 * pueda afirmar QUE se registro y en que secuencia, sin tocar la tabla real.
 *
 * Es append-only igual que el adaptador de verdad: no expone forma de modificar ni
 * de borrar un evento ya escrito (CLAUDE.md seccion 5).
 */
final class InMemoryAuditLogger implements AuditLogger
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<AuditEvent> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return array_map(static fn (AuditEvent $event): string => $event->eventType->value, $this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }
}
