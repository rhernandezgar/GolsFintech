<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Transicion de estado no permitida por la maquina de estados de la entidad.
 */
final class InvalidStateTransitionException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_STATE_TRANSITION';
    }

    public function userMessage(): string
    {
        return 'La solicitud no se encuentra en un estado que permita esta accion.';
    }
}
