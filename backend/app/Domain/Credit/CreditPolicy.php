<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Shared\Money;

/**
 * Parametros del motor de reglas (RF-05 y RF-06).
 *
 * Estan reunidos en una sola clase inmutable a proposito: el area de riesgos revisa
 * y ajusta numeros aqui, sin tocar el algoritmo, y las pruebas pueden construir una
 * politica distinta sin base de datos ni servicios externos (Fase 2 §PT-08). El
 * diseno no fija estas cifras; se documentan como parametros y no como reglas
 * inamovibles.
 *
 * Aforo: el pago mensual nunca puede rebasar el 30 % del ingreso mensual validado.
 * Es el limite que impide que el motor autorice creditos indebidos (riesgo R-08).
 */
final readonly class CreditPolicy
{
    /**
     * @param int $paymentCapacityPercent porcentaje del ingreso comprometible al mes
     * @param array<string, Money> $minimumMonthlyIncome ingreso minimo por tipo de credito
     * @param array<string, Money> $maximumAmount techo autorizado por tipo de credito
     * @param array<string, AnnualRate> $annualRate tasa anual ordinaria por tipo de credito
     */
    public function __construct(
        public int $paymentCapacityPercent,
        public int $minimumAge,
        public int $maximumAge,
        public Money $minimumOfferAmount,
        public array $minimumMonthlyIncome,
        public array $maximumAmount,
        public array $annualRate,
    ) {
    }

    /** Politica vigente. Cualquier cambio de cifras se hace aqui y se prueba en PT-08. */
    public static function default(): self
    {
        return new self(
            paymentCapacityPercent: 30,
            minimumAge: 18,
            maximumAge: 74,
            minimumOfferAmount: Money::fromDecimalString('1000.00'),
            minimumMonthlyIncome: [
                CreditType::Microcredit->value => Money::fromDecimalString('3000.00'),
                CreditType::Personal->value => Money::fromDecimalString('8000.00'),
                CreditType::Business->value => Money::fromDecimalString('15000.00'),
            ],
            maximumAmount: [
                CreditType::Microcredit->value => Money::fromDecimalString('30000.00'),
                CreditType::Personal->value => Money::fromDecimalString('150000.00'),
                CreditType::Business->value => Money::fromDecimalString('500000.00'),
            ],
            annualRate: [
                CreditType::Microcredit->value => AnnualRate::fromPercentageString('60.00'),
                CreditType::Personal->value => AnnualRate::fromPercentageString('36.00'),
                CreditType::Business->value => AnnualRate::fromPercentageString('42.00'),
            ],
        );
    }

    public function minimumIncomeFor(CreditType $type): Money
    {
        return $this->minimumMonthlyIncome[$type->value];
    }

    public function maximumAmountFor(CreditType $type): Money
    {
        return $this->maximumAmount[$type->value];
    }

    public function annualRateFor(CreditType $type): AnnualRate
    {
        return $this->annualRate[$type->value];
    }

    public function isEligibleAge(int $age): bool
    {
        return $age >= $this->minimumAge && $age <= $this->maximumAge;
    }
}
