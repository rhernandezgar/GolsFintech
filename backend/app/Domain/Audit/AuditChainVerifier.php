<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Recorre la bitacora y comprueba los dos invariantes de la cadena.
 *
 * Por cada registro, en orden de id ascendente:
 *
 *   1. ESLABON: su previous_hash es el current_hash del registro que lo precede
 *      (o null si es el primero).
 *   2. CONTENIDO: el hash recalculado sobre sus propios campos coincide con su
 *      current_hash almacenado.
 *
 * Hacen falta las dos, y esta es la parte facil de equivocar. Comprobar solo (2)
 * detecta que alguien altero un registro, pero NO que borrara uno intermedio ni que
 * insertara uno nuevo: en ambos casos cada fila superviviente sigue siendo coherente
 * consigo misma. Lo que delata esas dos manipulaciones es (1), la costura entre
 * registros consecutivos.
 *
 * El contenido se recalcula con el previous_hash ALMACENADO del propio registro, no
 * con el esperado. Asi cada manipulacion se reporta por lo que es: un borrado
 * intermedio sale como un unico eslabon roto, y no ademas como un contenido alterado
 * que no lo esta.
 */
final readonly class AuditChainVerifier
{
    public function __construct(private AuditChain $chain) {}

    /** @param iterable<AuditChainLink> $links ordenados por id ascendente */
    public function verify(iterable $links): ChainVerificationResult
    {
        $expectedPreviousHash = AuditChain::GENESIS;
        $previousRecordId = null;
        $verifiedRecords = 0;
        $breaks = [];
        $tipHash = null;
        $tipRecordId = null;

        foreach ($links as $link) {
            $verifiedRecords++;

            if (! $this->sameHash($link->previousHash, $expectedPreviousHash)) {
                $breaks[] = $this->breakAt(ChainBreakKind::LinkMismatch, $link, $previousRecordId,
                    expected: $expectedPreviousHash, stored: $link->previousHash);
            }

            $recomputed = $this->chain->hashOfFields(
                previousHash: $link->previousHash,
                prospectId: $link->prospectId,
                affectedEntity: $link->affectedEntity,
                affectedEntityId: $link->affectedEntityId,
                eventType: $link->eventType,
                actor: $link->actor,
                ipAddress: $link->ipAddress,
                eventAt: $link->eventAt,
                metadata: $link->metadata,
            );

            if (! hash_equals($recomputed, $link->currentHash)) {
                $breaks[] = $this->breakAt(ChainBreakKind::ContentAltered, $link, $previousRecordId,
                    expected: $recomputed, stored: $link->currentHash);
            }

            $expectedPreviousHash = $link->currentHash;
            $previousRecordId = $link->id;
            $tipHash = $link->currentHash;
            $tipRecordId = $link->id;
        }

        return new ChainVerificationResult($verifiedRecords, $breaks, $tipHash, $tipRecordId);
    }

    private function breakAt(
        ChainBreakKind $kind,
        AuditChainLink $link,
        ?int $previousRecordId,
        ?string $expected,
        ?string $stored,
    ): ChainBreak {
        return new ChainBreak(
            kind: $kind,
            recordId: $link->id,
            previousRecordId: $previousRecordId,
            eventType: $link->eventType,
            actor: $link->actor,
            eventAt: $link->eventAt->format('Y-m-d H:i:s'),
            expected: $expected,
            stored: $stored,
        );
    }

    /** hash_equals no admite null, y el primer eslabon lo lleva por definicion. */
    private function sameHash(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return hash_equals($left, $right);
    }
}
