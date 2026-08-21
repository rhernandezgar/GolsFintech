<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Operacion monetaria invalida: importe mal formado, negativo o con monedas distintas.
 */
final class InvalidMoneyException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_MONEY';
    }

    public function userMessage(): string
    {
        return 'El monto capturado no es valido.';
    }
}
