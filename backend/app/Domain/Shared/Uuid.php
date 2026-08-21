<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Identificador publico de una entidad (columna public_id).
 *
 * Se genera con random_bytes (CSPRNG del propio PHP) y no con una libreria externa
 * para que el nucleo del dominio siga sin dependencias. Es el unico identificador
 * que sale hacia el cliente: el id numerico de la base de datos nunca se expone,
 * asi no se puede enumerar el padron de prospectos.
 */
final readonly class Uuid
{
    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        $bytes = random_bytes(16);
        // Version 4 y variante RFC 4122.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new InvalidArgumentException('El identificador publico no tiene formato UUID.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
