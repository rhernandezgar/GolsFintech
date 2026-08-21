<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La validacion de identidad contra INE y RENAPO no esta en estado verificado.
 */
final class IdentityNotVerifiedException extends DomainException
{
    public function errorCode(): string
    {
        return 'IDENTITY_NOT_VERIFIED';
    }

    public function userMessage(): string
    {
        return 'No pudimos validar tu identidad. Acude a soporte con tu folio de verificacion.';
    }
}
