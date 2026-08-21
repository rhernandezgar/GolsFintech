<?php

declare(strict_types=1);

namespace App\Domain\Card;

/** Marca de la tarjeta emitida (cards.brand). */
enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Amex = 'amex';
    case Other = 'other';
}
