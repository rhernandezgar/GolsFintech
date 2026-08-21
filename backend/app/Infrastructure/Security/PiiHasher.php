<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Hash determinista de un identificador personal, para las columnas curp_hash y
 * rfc_hash: permiten buscar y detectar duplicados sin descifrar la columna ni
 * exponer el dato en claro.
 *
 * Se usa HMAC-SHA-256 con una llave del entorno y no SHA-256 a secas. El motivo es
 * concreto: el espacio de CURP validas es pequeno y enumerable, asi que un SHA-256
 * simple podria revertirse por fuerza bruta con solo obtener una copia de la tabla.
 * Con HMAC, sin la llave, ese ataque no es viable. Sigue siendo SHA-256 y sigue
 * siendo determinista, que es lo que la columna necesita.
 *
 * MD5 y SHA-1 estan prohibidos (CLAUDE.md seccion 6.6).
 */
final readonly class PiiHasher
{
    public function __construct(private string $key)
    {
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', strtoupper(trim($value)), $this->key);
    }

    public function matches(string $value, string $hash): bool
    {
        return hash_equals($this->hash($value), $hash);
    }
}
