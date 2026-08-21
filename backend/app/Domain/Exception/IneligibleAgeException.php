<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La edad del prospecto queda fuera del rango autorizado por la politica de credito.
 */
final class IneligibleAgeException extends DomainException
{
    public function errorCode(): string
    {
        return 'INELIGIBLE_AGE';
    }

    public function userMessage(): string
    {
        return 'Por el momento no podemos ofrecerte un credito con la informacion proporcionada.';
    }
}
