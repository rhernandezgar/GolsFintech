<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Exception\InvalidEmailException;

/**
 * Correo de contacto del prospecto. Criterio de lista de permitidos (Fase 3 §4.5):
 * se define que se acepta, no que se rechaza.
 */
final readonly class Email
{
    private const MAX_LENGTH = 254;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '' || strlen($value) > self::MAX_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidEmailException('Direccion de correo con formato invalido.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
