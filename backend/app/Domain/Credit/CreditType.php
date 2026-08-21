<?php

declare(strict_types=1);

namespace App\Domain\Credit;

/** Linea de credito resuelta por el motor de reglas (credit_applications.credit_type). */
enum CreditType: string
{
    case Personal = 'personal';
    case Business = 'business';
    case Microcredit = 'microcredit';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Credito personal',
            self::Business => 'Credito para negocio',
            self::Microcredit => 'Microcredito',
        };
    }
}
