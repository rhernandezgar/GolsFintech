<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * El expediente del prospecto no tiene todos los datos obligatorios para pasar
 * a P4. Lleva la lista de campos que faltan para que el endpoint pueda
 * responder 422 indicando exactamente cuales sin filtrar detalle tecnico
 * (regla de seguridad no negociable 8).
 *
 * Se separa de `InvalidStateTransitionException` a proposito: confirmar con
 * campos vacios no es un error de flujo, es una regla de negocio con su
 * propio codigo estable para la API. Mezclarlas obligaria al controlador a
 * inspeccionar el texto para decidir el mensaje.
 */
final class ProspectDataIncompleteException extends DomainException
{
    /** @param list<string> $missingFields */
    public function __construct(private readonly array $missingFields)
    {
        parent::__construct(sprintf('Faltan campos obligatorios: %s.', implode(', ', $missingFields)));
    }

    /** @return list<string> */
    public function missingFields(): array
    {
        return $this->missingFields;
    }

    public function errorCode(): string
    {
        return 'PROSPECT_DATA_INCOMPLETE';
    }

    public function userMessage(): string
    {
        return 'Faltan datos obligatorios para confirmar la captura.';
    }
}
