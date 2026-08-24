<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La simulacion ya fue aceptada o rechazada: no puede volver a decidirse. Un
 * segundo intento de aceptar la misma oferta no debe crear dos clientes; se
 * corta aqui con codigo estable para que el endpoint responda 422 —o 409, si
 * asi lo decide la capa HTTP— y no propague el error de flujo generico.
 *
 * Se separa de `InvalidStateTransition` a proposito: doble aceptacion es un
 * caso de reintento (P6 con doble click, reintento del navegador tras timeout,
 * red intermitente) que merece su propio mensaje al usuario ("ya autorizamos
 * esta oferta") en lugar del generico "estado no permite esta accion".
 */
final class CreditSimulationAlreadyDecidedException extends DomainException
{
    public function errorCode(): string
    {
        return 'CREDIT_SIMULATION_ALREADY_DECIDED';
    }

    public function userMessage(): string
    {
        return 'Esta oferta ya fue decidida. No se puede volver a aceptar.';
    }
}
