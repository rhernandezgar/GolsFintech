<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La CURP no cumple la estructura oficial o su digito verificador no coincide.
 */
final class InvalidCurpException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_CURP';
    }

    public function userMessage(): string
    {
        return 'La CURP capturada no es valida. Revisala e intentalo de nuevo.';
    }
}
