<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Exception\InvalidPhoneNumberException;

/**
 * Telefono nacional de Mexico: diez digitos. Se aceptan separadores al capturar y
 * se guardan siempre normalizados, para que la deteccion de duplicados no dependa
 * del formato con el que se escribio.
 */
final readonly class PhoneNumber
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        // Se tolera el prefijo internacional de Mexico, pero se descarta al guardar.
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }

        if (preg_match('/^[2-9]\d{9}$/', $digits) !== 1) {
            throw new InvalidPhoneNumberException('El telefono debe tener diez digitos nacionales.');
        }

        return new self($digits);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
