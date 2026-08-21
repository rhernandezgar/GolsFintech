<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Shared\Money;

/**
 * Condiciones calculadas por el motor de reglas y mostradas en P5: tipo de credito,
 * capacidad de pago, monto, tasa, CAT informativo, plazo, pago mensual y total a
 * pagar (Fase 2 §P5). Es un objeto de valor: no se persiste por si mismo, se
 * proyecta sobre credit_applications y credit_simulations.
 */
final readonly class CreditOffer
{
    public function __construct(
        public CreditType $creditType,
        public Money $validatedMonthlyIncome,
        public Money $paymentCapacity,
        public Money $proposedAmount,
        public AnnualRate $annualRate,
        public AnnualRate $cat,
        public Term $term,
        public Money $estimatedMonthlyPayment,
        public Money $totalPayable,
    ) {
    }

    /** Costo financiero total del credito: lo que se paga por encima del capital. */
    public function totalInterest(): Money
    {
        return $this->totalPayable->subtract($this->proposedAmount);
    }
}
