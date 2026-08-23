<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Card\IssuedCard;
use App\Domain\Customer\NewCustomerRegistration;
use App\Domain\Customer\RegisteredCustomer;
use App\Domain\Port\CustomerRegistry;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Alta del cliente y su linea de credito (`register`), y adjuncion posterior de
 * la tarjeta emitida por el procesador (`attachCard`).
 *
 * `register` escribe cliente y linea **en una sola transaccion**: un cliente sin
 * linea es un estado que el negocio no contempla, y ese es el argumento con el
 * que la Fase 2 descarto microservicios. Partirlo lo contradiria.
 *
 * `attachCard` va aparte y **fuera de esa transaccion** a proposito. La tarjeta
 * la emite un tercero: llamarlo con una transaccion abierta seria sostener
 * bloqueos en la base de datos durante toda la latencia de la red. Si el emisor
 * falla, el cliente y la linea ya estan creados —el credito autorizado no se
 * pierde—; el expediente queda en un estado recuperable, sin tarjeta, y la
 * pantalla de P6 lo dice.
 *
 * De la tarjeta se guarda el TOKEN y los ultimos cuatro digitos. El PAN
 * completo no llega nunca a esta aplicacion: `IssuedCard` no tiene siquiera un
 * campo donde ponerlo (regla de seguridad 2, PCI DSS, RS-03).
 */
final readonly class EloquentCustomerRegistry implements CustomerRegistry
{
    public function register(NewCustomerRegistration $registration): RegisteredCustomer
    {
        return DB::transaction(function () use ($registration): RegisteredCustomer {
            $customer = CustomerRecord::query()->create([
                'public_id' => Uuid::generate()->value,
                'prospect_id' => $registration->prospectId,
                'credit_application_id' => $registration->creditApplicationId,
                'customer_number' => $registration->customerNumber->value,
                'full_name' => $registration->fullName,
                'contract_folio' => $registration->contractFolio->value,
                'customer_status' => 'active',
                'contract_version' => $registration->contractVersion,
                'consent_at' => $registration->consentAt,
                'activated_at' => $registration->consentAt,
            ]);

            $line = CreditLineRecord::query()->create([
                'public_id' => Uuid::generate()->value,
                'customer_id' => $customer->id,
                'credit_simulation_id' => $registration->creditSimulationId,
                'authorized_amount' => $registration->authorizedAmount->toDecimalString(),
                // Al abrir la linea no hay disposiciones: el disponible es el
                // autorizado completo.
                'available_balance' => $registration->authorizedAmount->toDecimalString(),
                'currency' => $registration->authorizedAmount->currency,
                'annual_rate' => $registration->annualRate->toDecimalString(),
                'term_months' => $registration->termMonths,
                'line_status' => 'active',
                'opened_at' => $registration->consentAt,
            ]);

            return new RegisteredCustomer(
                customerId: (int) $customer->id,
                creditLineId: (int) $line->id,
                customerNumber: (string) $customer->customer_number,
                contractFolio: (string) $customer->contract_folio,
                authorizedAmount: $registration->authorizedAmount->toDecimalString(),
                currency: $registration->authorizedAmount->currency,
                lineStatus: (string) $line->line_status,
            );
        });
    }

    public function attachCard(
        RegisteredCustomer $customer,
        IssuedCard $card,
        DateTimeImmutable $issuedAt,
    ): RegisteredCustomer {
        $record = CardRecord::query()->create([
            'public_id' => Uuid::generate()->value,
            'customer_id' => $customer->customerId,
            'credit_line_id' => $customer->creditLineId,
            'tokenized_card_number' => $card->tokenizedCardNumber,
            'last_four' => $card->lastFour,
            'brand' => $card->brand->value,
            'expiration_month' => $card->expirationMonth,
            'expiration_year' => $card->expirationYear,
            'card_status' => 'issued',
            'issued_at' => $issuedAt,
        ]);

        return $customer->withCard($card, (string) $record->card_status);
    }
}
