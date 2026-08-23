<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Domain\Card\IssuedCard;

/**
 * Lo que P6 muestra tras la autorizacion.
 *
 * La tarjeta es **opcional** a proposito. El alta del cliente y la apertura de
 * la linea son escrituras propias; la emision de la tarjeta la hace un tercero
 * y puede fallar sola. Cuando falla, el credito ya esta autorizado y la linea
 * abierta: dar eso por perdido seria peor que quedarse sin tarjeta un rato. El
 * expediente queda en un estado recuperable y la pantalla lo dice.
 *
 * De la tarjeta viajan **solo los ultimos cuatro digitos y la vigencia**. El
 * token no sale de la base de datos y el PAN completo no existe en ningun sitio
 * de esta aplicacion (regla de seguridad 2, PCI DSS, RS-03).
 */
final readonly class RegisteredCustomer
{
    public function __construct(
        public int $customerId,
        public int $creditLineId,
        public string $customerNumber,
        public string $contractFolio,
        public string $authorizedAmount,
        public string $currency,
        public string $lineStatus,
        public ?string $cardLastFour = null,
        public ?string $cardBrand = null,
        public ?string $cardExpiration = null,
        public ?string $cardStatus = null,
    ) {}

    public function withCard(IssuedCard $card, string $cardStatus): self
    {
        return new self(
            customerId: $this->customerId,
            creditLineId: $this->creditLineId,
            customerNumber: $this->customerNumber,
            contractFolio: $this->contractFolio,
            authorizedAmount: $this->authorizedAmount,
            currency: $this->currency,
            lineStatus: $this->lineStatus,
            cardLastFour: $card->lastFour,
            cardBrand: $card->brand->value,
            cardExpiration: sprintf('%02d/%02d', $card->expirationMonth, $card->expirationYear % 100),
            cardStatus: $cardStatus,
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'customer_number' => $this->customerNumber,
            'contract_folio' => $this->contractFolio,
            'authorized_amount' => $this->authorizedAmount,
            'currency' => $this->currency,
            'line_status' => $this->lineStatus,
            'card_last_four' => $this->cardLastFour,
            'card_brand' => $this->cardBrand,
            'card_expiration' => $this->cardExpiration,
            'card_status' => $this->cardStatus,
        ];
    }
}
