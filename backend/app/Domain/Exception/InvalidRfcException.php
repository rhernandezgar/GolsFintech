<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * El RFC no cumple la estructura oficial o su digito verificador no coincide.
 */
final class InvalidRfcException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_RFC';
    }

    public function userMessage(): string
    {
        return 'El RFC capturado no es valido. Revisalo e intentalo de nuevo.';
    }
}
