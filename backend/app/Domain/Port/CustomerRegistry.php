<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Card\IssuedCard;
use App\Domain\Customer\NewCustomerRegistration;
use App\Domain\Customer\RegisteredCustomer;
use DateTimeImmutable;

/**
 * Alta del cliente con su linea de credito y su tarjeta (P6, RF-09 y RF-10).
 *
 * Son dos metodos y no uno porque son dos cosas distintas. `register` escribe
 * cliente y linea juntos, en una transaccion: un cliente sin linea es un estado
 * que el negocio no contempla. `attachCard` va aparte porque la tarjeta la
 * emite un tercero, y llamar a un servicio externo con una transaccion abierta
 * es sostener bloqueos de base de datos durante toda la latencia de la red.
 *
 * La consecuencia esta asumida: si la emision falla, el credito ya esta
 * autorizado y la linea abierta, y el expediente queda en un estado recuperable
 * —cliente con linea, sin tarjeta— en vez de perderse entero.
 */
interface CustomerRegistry
{
    /** Cliente y linea de credito, en una transaccion. Sin tarjeta todavia. */
    public function register(NewCustomerRegistration $registration): RegisteredCustomer;

    public function attachCard(
        RegisteredCustomer $customer,
        IssuedCard $card,
        DateTimeImmutable $issuedAt,
    ): RegisteredCustomer;
}
