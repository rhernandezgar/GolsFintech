<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * La CURP corresponde a un cliente ya dado de alta.
 *
 * Es un caso DISTINTO de «tienes una solicitud en curso» y por eso tiene
 * excepcion propia: no caduca, no se resuelve esperando y mandarlo a la misma
 * cola de diez minutos seria decirle que vuelva a un sitio donde nunca va a
 * poder entrar. Lo que necesita es la via de cliente, no la de solicitante.
 */
final class ProspectAlreadyCustomerException extends DomainException
{
    public function __construct()
    {
        parent::__construct('La CURP corresponde a un cliente ya registrado.');
    }

    public function errorCode(): string
    {
        return 'PROSPECT_ALREADY_CUSTOMER';
    }

    public function userMessage(): string
    {
        return 'Con esos datos ya existe una cuenta de cliente. Ingresa con tu numero de cliente '
            .'o comunicate con soporte si necesitas otra linea de credito.';
    }
}
