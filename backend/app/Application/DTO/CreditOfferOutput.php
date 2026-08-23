<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Credit\CreditOffer;
use App\Domain\Credit\CreditSimulation;
use DateTimeImmutable;

/**
 * Proyeccion de la oferta para la pantalla P5. Se devuelven cadenas ya formateadas
 * y ningun objeto del dominio: la capa de entrada no debe poder alterar el estado
 * del nucleo, y el importe viaja como decimal exacto, no como flotante de JSON.
 *
 * A partir de que P5 persiste la simulacion, viajan tambien su identificador
 * publico y su vigencia: son lo que P6 usa para aceptarla, y sin ellos el
 * cliente no puede referenciar la oferta que se le mostro.
 */
final readonly class CreditOfferOutput
{
    public function __construct(
        public string $simulationPublicId,
        public string $simulationFolio,
        public string $creditType,
        public string $creditTypeLabel,
        public string $paymentCapacity,
        public string $proposedAmount,
        public string $annualRatePercentage,
        public string $catPercentage,
        public int $termMonths,
        public string $estimatedMonthlyPayment,
        public string $totalPayable,
        public string $currency,
        public string $expiresAt,
    ) {}

    public static function fromSimulation(CreditSimulation $simulation): self
    {
        return self::fromOffer($simulation->offer(), $simulation);
    }

    public static function fromOffer(CreditOffer $offer, CreditSimulation $simulation): self
    {
        return new self(
            simulationPublicId: $simulation->publicId()->value,
            simulationFolio: $simulation->simulationFolio()->value,
            creditType: $offer->creditType->value,
            creditTypeLabel: $offer->creditType->label(),
            paymentCapacity: $offer->paymentCapacity->toDecimalString(),
            proposedAmount: $offer->proposedAmount->toDecimalString(),
            annualRatePercentage: $offer->annualRate->toPercentageString(),
            catPercentage: $offer->cat->toPercentageString(),
            termMonths: $offer->term->months,
            estimatedMonthlyPayment: $offer->estimatedMonthlyPayment->toDecimalString(),
            totalPayable: $offer->totalPayable->toDecimalString(),
            currency: $offer->proposedAmount->currency,
            expiresAt: $simulation->expiresAt()->format(DateTimeImmutable::ATOM),
        );
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'simulation_public_id' => $this->simulationPublicId,
            'simulation_folio' => $this->simulationFolio,
            'credit_type' => $this->creditType,
            'credit_type_label' => $this->creditTypeLabel,
            'payment_capacity' => $this->paymentCapacity,
            'proposed_amount' => $this->proposedAmount,
            'annual_rate_percentage' => $this->annualRatePercentage,
            'cat_percentage' => $this->catPercentage,
            'term_months' => $this->termMonths,
            'estimated_monthly_payment' => $this->estimatedMonthlyPayment,
            'total_payable' => $this->totalPayable,
            'currency' => $this->currency,
            'expires_at' => $this->expiresAt,
        ];
    }
}
