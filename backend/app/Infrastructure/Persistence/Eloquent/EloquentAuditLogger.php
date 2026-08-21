<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Audit\AuditChain;
use App\Domain\Audit\AuditEvent;
use App\Domain\Port\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de la bitacora append-only con encadenamiento SHA-256.
 *
 * La escritura va dentro de una transaccion y el ultimo eslabon se lee con
 * lockForUpdate: sin ese bloqueo, dos peticiones simultaneas leerian el mismo
 * previous_hash y producirian una bifurcacion de la cadena. El indice UNIQUE de
 * current_hash es la ultima red de proteccion.
 */
final readonly class EloquentAuditLogger implements AuditLogger
{
    public function __construct(private AuditChain $chain)
    {
    }

    public function append(AuditEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $previousHash = AuditLogRecord::query()
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('current_hash');

            $previousHash = $previousHash === null ? null : (string) $previousHash;

            AuditLogRecord::query()->create([
                'prospect_id' => $event->prospectId,
                'affected_entity' => $event->affectedEntity,
                'affected_entity_id' => $event->affectedEntityId,
                'event_type' => $event->eventType->value,
                'actor' => $event->actor(),
                'ip_address' => $event->ipAddress(),
                'metadata' => $event->metadata,
                'event_at' => $event->eventAt,
                'previous_hash' => $previousHash,
                'current_hash' => $this->chain->hash($event, $previousHash),
            ]);
        });
    }
}
