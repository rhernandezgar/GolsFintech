<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use DateTimeImmutable;
use DateTimeInterface;

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
 *
 * ## Que entra en el hash y que no
 *
 * El material es la concatenacion con '|' de estos nueve elementos, EN ESTE ORDEN:
 *
 *   1. previous_hash        (cadena vacia en el primer registro)
 *   2. prospect_id          (cadena vacia si es nulo)
 *   3. affected_entity
 *   4. affected_entity_id   (cadena vacia si es nulo)
 *   5. event_type
 *   6. actor
 *   7. ip_address           (cadena vacia si es nulo)
 *   8. event_at             en UTC y formato ATOM, con precision de segundo
 *   9. metadata             JSON con las claves ordenadas recursivamente
 *
 * Es decir: **todas las columnas de audit_logs salvo dos**, y las dos exclusiones
 * son deliberadas y estan acotadas:
 *
 *   - `id`: lo asigna el AUTO_INCREMENT durante el INSERT, o sea despues de calcular
 *     el hash. Incluirlo obligaria a escribir en dos pasos —insertar y luego
 *     actualizar el hash—, y una bitacora append-only no admite ese UPDATE. No hace
 *     falta: renumerar o reordenar registros cambia quien precede a quien, y eso
 *     rompe la comprobacion de eslabon de AuditChainVerifier.
 *   - `current_hash`: es el resultado del calculo, no puede ser tambien su entrada.
 *
 * Esto importa mas de lo que parece. `ip_address` y `metadata` estan DENTRO del
 * material a proposito: son justo los campos que un atacante querria retocar —de
 * que direccion vino la operacion, con que datos se hizo— y si quedaran fuera se
 * podrian reescribir sin romper la cadena, con el hash siguiendo en verde.
 *
 * `AuditChainTest::test_the_hashed_columns_cover_the_whole_table` fija esta lista
 * contra el esquema real: anadir una columna a audit_logs sin decidir si entra en
 * el hash pone la prueba en rojo.
 *
 * ## Que NO garantiza este esquema
 *
 * El hash no lleva llave y el material es publico, de modo que quien tenga escritura
 * sobre la tabla puede recalcular una cadena entera coherente. Lo que la cadena
 * detecta es la manipulacion parcial —alterar, borrar o insertar sin rehacer todo lo
 * posterior—. Contra la reescritura total del tramo final hace falta un ancla
 * externa: por eso `audit:verify-chain` imprime el hash de la punta y acepta
 * `--expect-tip` (vease VerifyAuditChainCommand).
 */
final class AuditChain
{
    /** Valor de previous_hash del primer registro de la cadena. */
    public const GENESIS = null;

    /** Columnas de audit_logs que forman el material del hash. */
    public const HASHED_COLUMNS = [
        'previous_hash', 'prospect_id', 'affected_entity', 'affected_entity_id',
        'event_type', 'actor', 'ip_address', 'event_at', 'metadata',
    ];

    /** Columnas deliberadamente fuera del material. Vease el comentario de la clase. */
    public const UNHASHED_COLUMNS = ['id', 'current_hash'];

    public function hash(AuditEvent $event, ?string $previousHash): string
    {
        return $this->hashOfFields(
            previousHash: $previousHash,
            prospectId: $event->prospectId,
            affectedEntity: $event->affectedEntity,
            affectedEntityId: $event->affectedEntityId,
            eventType: $event->eventType->value,
            actor: $event->actor(),
            ipAddress: $event->ipAddress(),
            eventAt: $event->eventAt,
            // Ya enmascarados: AuditEvent los pasa por SensitiveDataMasker en su
            // constructor, asi que se firma exactamente lo que se va a almacenar.
            metadata: $event->metadata,
        );
    }

    /**
     * Recalcula el hash a partir de los campos sueltos.
     *
     * Es el mismo material que hash(), y existe para que la verificacion trabaje
     * sobre la fila almacenada en vez de reconstruir un AuditEvent: si hubiera dos
     * canonicalizaciones distintas, una podria derivar de la otra sin que ninguna
     * prueba lo notara y la verificacion daria falsos negativos.
     *
     * @param  array<array-key, mixed>  $metadata
     */
    public function hashOfFields(
        ?string $previousHash,
        ?int $prospectId,
        string $affectedEntity,
        ?int $affectedEntityId,
        string $eventType,
        string $actor,
        ?string $ipAddress,
        DateTimeInterface $eventAt,
        array $metadata,
    ): string {
        $this->sortRecursively($metadata);

        return hash('sha256', implode('|', [
            $previousHash ?? '',
            $prospectId === null ? '' : (string) $prospectId,
            $affectedEntity,
            $affectedEntityId === null ? '' : (string) $affectedEntityId,
            $eventType,
            $actor,
            $ipAddress ?? '',
            $this->canonicalTimestamp($eventAt),
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]));
    }

    /** Comprueba un eslabon ya escrito. */
    public function verify(AuditEvent $event, ?string $previousHash, string $currentHash): bool
    {
        return hash_equals($this->hash($event, $previousHash), $currentHash);
    }

    /**
     * UTC y precision de segundo, se reciba lo que se reciba.
     *
     * La columna event_at es un TIMESTAMP sin fracciones: si el material del hash
     * conservara los microsegundos del objeto en memoria, el valor firmado al
     * escribir no coincidiria con el que se lee de la base y la verificacion
     * fallaria sobre registros legitimos.
     */
    private function canonicalTimestamp(DateTimeInterface $eventAt): string
    {
        return (new DateTimeImmutable('@'.$eventAt->getTimestamp()))->format(DateTimeInterface::ATOM);
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
