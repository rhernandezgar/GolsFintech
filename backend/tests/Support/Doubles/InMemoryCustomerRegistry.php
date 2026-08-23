<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Card\IssuedCard;
use App\Domain\Customer\NewCustomerRegistration;
use App\Domain\Customer\RegisteredCustomer;
use App\Domain\Port\CustomerRegistry;
use App\Domain\Shared\Folio;
use DateTimeImmutable;
use RuntimeException;

/**
 * Doble en memoria del alta de clientes.
 *
 * Reproduce dos comportamientos que las pruebas necesitan poder observar:
 *
 * - `register` devuelve cliente y linea con identificadores propios; ejercita
 *   la misma asignacion "cliente y linea juntos" que el adaptador Eloquent hace
 *   en su transaccion.
 * - `attachCard` puede simularse fallando con `$this->failCardIssuance()` para
 *   comprobar que el use case emite `card.issuance_failed` y devuelve el
 *   cliente **sin tarjeta**, con el credito ya autorizado.
 */
final class InMemoryCustomerRegistry implements CustomerRegistry
{
    /** @var array<int, RegisteredCustomer> */
    private array $customers = [];

    /** @var array<int, IssuedCard> */
    private array $cards = [];

    private int $nextCustomerId = 1;

    private int $nextLineId = 1;

    private bool $failNextAttach = false;

    public function register(NewCustomerRegistration $registration): RegisteredCustomer
    {
        $customer = new RegisteredCustomer(
            customerId: $this->nextCustomerId++,
            creditLineId: $this->nextLineId++,
            customerNumber: $registration->customerNumber->value,
            contractFolio: $registration->contractFolio->value,
            authorizedAmount: $registration->authorizedAmount->toDecimalString(),
            currency: $registration->authorizedAmount->currency,
            lineStatus: 'active',
        );

        $this->customers[$customer->customerId] = $customer;

        return $customer;
    }

    public function attachCard(
        RegisteredCustomer $customer,
        IssuedCard $card,
        DateTimeImmutable $issuedAt,
    ): RegisteredCustomer {
        if ($this->failNextAttach) {
            $this->failNextAttach = false;
            throw new RuntimeException('El emisor de tarjetas no respondio (fingido en el doble).');
        }

        $this->cards[$customer->customerId] = $card;
        $withCard = $customer->withCard($card, 'issued');
        $this->customers[$customer->customerId] = $withCard;

        return $withCard;
    }

    public function findByCustomerNumber(Folio $customerNumber): ?RegisteredCustomer
    {
        foreach ($this->customers as $customer) {
            if ($customer->customerNumber === $customerNumber->value) {
                return $customer;
            }
        }

        return null;
    }

    /** Provoca que la proxima llamada a `attachCard` reviente. */
    public function failNextCardIssuance(): void
    {
        $this->failNextAttach = true;
    }

    public function customerCount(): int
    {
        return count($this->customers);
    }

    public function cardCount(): int
    {
        return count($this->cards);
    }
}
