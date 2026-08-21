<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * El ingreso mensual validado no alcanza el minimo de ninguna linea de credito (RF-06).
 */
final class InsufficientIncomeException extends DomainException
{
    public function errorCode(): string
    {
        return 'INSUFFICIENT_INCOME';
    }

    public function userMessage(): string
    {
        return 'Por el momento no podemos ofrecerte un credito con la informacion proporcionada.';
    }
}
