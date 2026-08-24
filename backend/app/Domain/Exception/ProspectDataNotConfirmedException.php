<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * P4 se ejecuta despues de la confirmacion de P2 (Fase 1). Si el prospecto no
 * ha confirmado sus datos, validar contra INE y RENAPO ejecutaria consultas
 * sobre un expediente incompleto: se cortan aqui con codigo estable.
 *
 * Se separa de `InvalidStateTransition` a proposito: no es un error de
 * maquinaria del dominio, es una precondicion de flujo que la capa HTTP
 * traduce a 422 con un mensaje que le dice al usuario que confirme antes.
 */
final class ProspectDataNotConfirmedException extends DomainException
{
    public function errorCode(): string
    {
        return 'PROSPECT_DATA_NOT_CONFIRMED';
    }

    public function userMessage(): string
    {
        return 'Confirma tus datos antes de validar la identidad.';
    }
}
