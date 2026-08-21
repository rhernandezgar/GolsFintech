<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Encadenamiento SHA-256 de la bitacora.
 *
 * current_hash = SHA-256(previous_hash + contenido canonico del evento). Alterar o
 * borrar un registro rompe la cadena de todos los posteriores, que es lo que
 * convierte a la bitacora en evidencia y no en un simple listado.
 *
 * SHA-256, nunca MD5 ni SHA-1 (regla de seguridad 6). La serializacion es canonica
 * —claves ordenadas y fecha en formato fijo— para que el mismo evento produzca
 * siempre el mismo hash y la verificacion sea reproducible.
 */
final class AuditChain
{
    /** Valor de previous_hash del primer registro de la cadena. */
    public const GENESIS = null;

    public function hash(AuditEvent $event, ?string $previousHash): string
    {
        return hash('sha256', $this->canonicalMaterial($event, $previousHash));
    }

    /** Comprueba un eslabon ya escrito. */
    public function verify(AuditEvent $event, ?string $previousHash, string $currentHash): bool
    {
        return hash_equals($this->hash($event, $previousHash), $currentHash);
    }

    private function canonicalMaterial(AuditEvent $event, ?string $previousHash): string
    {
        $metadata = $event->metadata;
        $this->sortRecursively($metadata);

        return implode('|', [
            $previousHash ?? '',
            $event->prospectId === null ? '' : (string) $event->prospectId,
            $event->affectedEntity,
            $event->affectedEntityId === null ? '' : (string) $event->affectedEntityId,
            $event->eventType->value,
            $event->actor(),
            $event->ipAddress() ?? '',
            $event->eventAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM),
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<array-key, mixed> $data */
    private function sortRecursively(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
        unset($value);

        ksort($data);
    }
}
