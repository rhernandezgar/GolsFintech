<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La simulacion caduco entre que se le mostro al prospecto y este trato de
 * aceptarla (Fase 2 §P5: la simulacion es informativa y no vinculante hasta su
 * autorizacion, y por eso lleva vigencia propia).
 *
 * Se separa de `InvalidStateTransitionException` a proposito: no es un error de
 * flujo, es una regla de negocio con su propio mensaje al usuario ("recalculala
 * y vuelvela a aceptar") y su propio codigo estable para la API. Mezclarlas
 * obligaria al endpoint a inspeccionar el texto para decidir el mensaje, y ese
 * es el patron que la seccion 6.2 de CLAUDE.md pide evitar.
 */
final class CreditSimulationExpiredException extends DomainException
{
    public function errorCode(): string
    {
        return 'CREDIT_SIMULATION_EXPIRED';
    }

    public function userMessage(): string
    {
        return 'La simulacion caduco. Vuelvela a calcular para aceptarla.';
    }
}
