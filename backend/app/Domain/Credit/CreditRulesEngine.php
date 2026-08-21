<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Exception\IneligibleAgeException;
use App\Domain\Exception\InsufficientIncomeException;
use App\Domain\Prospect\Prospect;
use App\Domain\Shared\Money;

/**
 * Motor de reglas de credito (RF-05, RF-06 y RF-07).
 *
 * Es la pieza mas sensible del sistema —el riesgo R-08 es precisamente que apruebe
 * creditos indebidos— y por eso vive en el nucleo, sin framework ni base de datos:
 * asi puede probarse de forma exhaustiva, aislada y determinista (PT-08).
 *
 * Se ejecuta EXCLUSIVAMENTE en el servidor. Lo que el navegador muestre en P5 es
 * informativo; ninguna condicion se toma del cliente (regla de seguridad 4).
 *
 * Secuencia de reglas:
 *   1. Edad dentro del rango autorizado.
 *   2. Tipo de credito segun ingreso mensual validado y si declara negocio propio.
 *   3. Capacidad de pago = aforo (30 %) sobre el ingreso mensual validado.
 *   4. Monto maximo cuyo pago mensual cabe en esa capacidad, acotado por el techo
 *      del tipo de credito y redondeado a la baja a multiplos de 100 pesos.
 *   5. Pago mensual y total a pagar recalculados sobre el monto ya redondeado.
 */
final class CreditRulesEngine
{
    public function __construct(
        private readonly CreditPolicy $policy,
        private readonly AmortizationCalculator $calculator = new AmortizationCalculator(),
    ) {
    }

    public function evaluateForProspect(Prospect $prospect, Term $term): CreditOffer
    {
        $income = $prospect->monthlyIncome();
        $age = $prospect->age();

        if ($income === null || $age === null) {
            throw new InsufficientIncomeException('El prospecto no tiene ingreso ni edad capturados.');
        }

        return $this->evaluate($income, $age, $prospect->businessType(), $term);
    }

    public function evaluate(Money $monthlyIncome, int $age, ?string $businessType, Term $term): CreditOffer
    {
        if (! $this->policy->isEligibleAge($age)) {
            throw new IneligibleAgeException(sprintf(
                'Edad %d fuera del rango autorizado (%d-%d).',
                $age,
                $this->policy->minimumAge,
                $this->policy->maximumAge
            ));
        }

        $creditType = $this->determineCreditType($monthlyIncome, $businessType);
        $paymentCapacity = $this->estimatePaymentCapacity($monthlyIncome);
        $rate = $this->policy->annualRateFor($creditType);

        $affordable = $this->calculator->maximumPrincipal($paymentCapacity, $rate, $term);
        $proposedAmount = Money::min($affordable, $this->policy->maximumAmountFor($creditType))
            ->floorToMajorUnits(100);

        if ($proposedAmount->isLessThan($this->policy->minimumOfferAmount)) {
            throw new InsufficientIncomeException(sprintf(
                'El monto ofrecible (%s) queda por debajo del minimo de originacion (%s).',
                $proposedAmount->toDecimalString(),
                $this->policy->minimumOfferAmount->toDecimalString()
            ));
        }

        $monthlyPayment = $this->calculator->monthlyPayment($proposedAmount, $rate, $term);

        return new CreditOffer(
            creditType: $creditType,
            validatedMonthlyIncome: $monthlyIncome,
            paymentCapacity: $paymentCapacity,
            proposedAmount: $proposedAmount,
            annualRate: $rate,
            cat: $this->calculator->effectiveAnnualRate($rate),
            term: $term,
            estimatedMonthlyPayment: $monthlyPayment,
            totalPayable: $monthlyPayment->multipliedBy($term->months),
        );
    }

    /**
     * Tipo de credito (RF-05). El credito para negocio exige, ademas del ingreso
     * minimo, que el prospecto haya declarado giro: sin actividad declarada no se
     * origina una linea de negocio.
     */
    public function determineCreditType(Money $monthlyIncome, ?string $businessType): CreditType
    {
        $hasBusiness = $businessType !== null && trim($businessType) !== '';

        if ($hasBusiness && $monthlyIncome->isGreaterThanOrEqualTo($this->policy->minimumIncomeFor(CreditType::Business))) {
            return CreditType::Business;
        }

        if ($monthlyIncome->isGreaterThanOrEqualTo($this->policy->minimumIncomeFor(CreditType::Personal))) {
            return CreditType::Personal;
        }

        if ($monthlyIncome->isGreaterThanOrEqualTo($this->policy->minimumIncomeFor(CreditType::Microcredit))) {
            return CreditType::Microcredit;
        }

        throw new InsufficientIncomeException(sprintf(
            'Ingreso mensual %s por debajo del minimo de cualquier linea.',
            $monthlyIncome->toDecimalString()
        ));
    }

    /** Capacidad de pago (RF-06): aforo sobre el ingreso mensual validado. */
    public function estimatePaymentCapacity(Money $monthlyIncome): Money
    {
        return $monthlyIncome->ratio($this->policy->paymentCapacityPercent, 100);
    }

    public function policy(): CreditPolicy
    {
        return $this->policy;
    }
}
