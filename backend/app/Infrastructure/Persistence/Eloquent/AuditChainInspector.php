<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Audit\AuditChainLink;
use App\Domain\Audit\AuditChainVerifier;
use App\Domain\Audit\ChainVerificationResult;
use DateTimeImmutable;
use Generator;

/**
 * Lee la bitacora almacenada y se la entrega al verificador de dominio.
 *
 * No se declara un puerto nuevo en Domain/Port para esto: los siete puertos del
 * diseno existen para que el dominio llame hacia afuera, y aqui el dominio no llama
 * a nadie —AuditChainVerifier recibe un iterable y no sabe de donde sale—. El
 * adaptador es este, y la regla de dependencia se respeta igual.
 *
 * La lectura es perezosa por paginas: la bitacora crece sin limite por definicion
 * (append-only) y cargarla entera en memoria para verificarla convertiria el comando
 * en inutilizable justo cuando mas hace falta, que es cuando ya hay historia.
 */
final readonly class AuditChainInspector
{
    public function __construct(private AuditChainVerifier $verifier) {}

    public function verifyStoredChain(int $chunkSize = 500): ChainVerificationResult
    {
        return $this->verifier->verify($this->storedLinks($chunkSize));
    }

    /** @return Generator<AuditChainLink> */
    private function storedLinks(int $chunkSize): Generator
    {
        // lazyById pagina por clave primaria ascendente, que es exactamente el orden
        // de la cadena, y no se descoloca si entran registros nuevos mientras corre.
        foreach (AuditLogRecord::query()->lazyById($chunkSize) as $record) {
            yield new AuditChainLink(
                id: (int) $record->id,
                prospectId: $record->prospect_id === null ? null : (int) $record->prospect_id,
                affectedEntity: (string) $record->affected_entity,
                affectedEntityId: $record->affected_entity_id === null ? null : (int) $record->affected_entity_id,
                eventType: (string) $record->event_type,
                actor: (string) $record->actor,
                ipAddress: $record->ip_address === null ? null : (string) $record->ip_address,
                eventAt: DateTimeImmutable::createFromInterface($record->event_at),
                metadata: $record->metadata ?? [],
                previousHash: $record->previous_hash === null ? null : (string) $record->previous_hash,
                currentHash: (string) $record->current_hash,
            );
        }
    }
}
