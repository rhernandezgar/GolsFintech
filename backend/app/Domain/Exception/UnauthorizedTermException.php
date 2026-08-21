<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Plazo fuera del catalogo autorizado en el dominio (VUL-02, CWE-20).
 */
final class UnauthorizedTermException extends DomainException
{
    public function errorCode(): string
    {
        return 'UNAUTHORIZED_TERM';
    }

    public function userMessage(): string
    {
        return 'El plazo seleccionado no esta disponible.';
    }
}
