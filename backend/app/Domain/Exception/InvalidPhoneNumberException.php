<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * El telefono no tiene diez digitos, formato nacional de Mexico.
 */
final class InvalidPhoneNumberException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_PHONE';
    }

    public function userMessage(): string
    {
        return 'El numero telefonico capturado no es valido.';
    }
}
