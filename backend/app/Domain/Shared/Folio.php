<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Folio de seguimiento (verificacion, solicitud, simulacion, contrato).
 *
 * Lleva prefijo y fecha para que soporte lo ubique de un vistazo, y una cola
 * aleatoria de 8 caracteres generada con random_int: no es correlativo, de modo que
 * conocer un folio no permite deducir otro ni estimar el volumen de solicitudes.
 */
final readonly class Folio
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const RANDOM_LENGTH = 8;

    private function __construct(public string $value) {}

    public static function generate(string $prefix, DateTimeImmutable $issuedAt): self
    {
        $prefix = strtoupper(trim($prefix));

        if (preg_match('/^[A-Z]{2,4}$/', $prefix) !== 1) {
            throw new InvalidArgumentException('El prefijo del folio debe tener de dos a cuatro letras.');
        }

        $random = '';
        $lastIndex = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $random .= self::ALPHABET[random_int(0, $lastIndex)];
        }

        return new self(sprintf('%s-%s-%s', $prefix, $issuedAt->format('Ymd'), $random));
    }

    public static function fromString(string $value): self
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{2,4}-\d{8}-[A-Z0-9]{8}$/', $value) !== 1) {
            throw new InvalidArgumentException('El folio no tiene el formato esperado.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
