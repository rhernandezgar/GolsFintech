<?php

declare(strict_types=1);

namespace App\Domain\Card;

use InvalidArgumentException;

/**
 * Tarjeta devuelta por el procesador (puerto CardIssuer).
 *
 * Contiene el TOKEN y los ultimos cuatro digitos, jamas el PAN completo: la
 * aplicacion no lo recibe, no lo guarda y no puede reconstruirlo (regla de
 * seguridad 2, PCI DSS). Que el objeto de valor no tenga siquiera un campo donde
 * ponerlo es intencional.
 */
final readonly class IssuedCard
{
    public function __construct(
        public string $tokenizedCardNumber,
        public string $lastFour,
        public CardBrand $brand,
        public int $expirationMonth,
        public int $expirationYear,
    ) {
        if (preg_match('/^\d{4}$/', $lastFour) !== 1) {
            throw new InvalidArgumentException('Los ultimos cuatro digitos deben ser exactamente cuatro digitos.');
        }

        if ($tokenizedCardNumber === '' || strlen($tokenizedCardNumber) > 64) {
            throw new InvalidArgumentException('Token de tarjeta invalido.');
        }

        if ($expirationMonth < 1 || $expirationMonth > 12) {
            throw new InvalidArgumentException('Mes de expiracion fuera de rango.');
        }

        // Un token que parece un PAN indica que el adaptador esta devolviendo el
        // numero real: se corta aqui antes de que llegue a la base de datos.
        if (preg_match('/^\d{13,19}$/', $tokenizedCardNumber) === 1) {
            throw new InvalidArgumentException('El token no puede ser un numero de tarjeta.');
        }
    }

    /** Unica forma de mostrar la tarjeta en pantalla. */
    public function maskedNumber(): string
    {
        return '**** **** **** '.$this->lastFour;
    }
}
