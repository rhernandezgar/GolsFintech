<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La direccion de correo no tiene un formato valido.
 */
final class InvalidEmailException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_EMAIL';
    }

    public function userMessage(): string
    {
        return 'El correo electronico capturado no es valido.';
    }
}
