<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Card\IssuedCard;

/**
 * Puerto del procesador de tarjetas. La aplicacion NUNCA recibe ni almacena el PAN
 * completo: el procesador devuelve un token y los ultimos cuatro digitos (PCI DSS,
 * RS-03 y regla de seguridad 2).
 */
interface CardIssuer
{
    public function issue(int $customerId, int $creditLineId): IssuedCard;
}
