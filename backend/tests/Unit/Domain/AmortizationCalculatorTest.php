<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Credit\AmortizationCalculator;
use App\Domain\Credit\AnnualRate;
use App\Domain\Credit\Term;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class AmortizationCalculatorTest extends TestCase
{
    private AmortizationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AmortizationCalculator();
    }

    /**
     * Valor de referencia calculado aparte con la formula de la anualidad:
     * 10 000 al 36 % anual (3 % mensual) a 12 meses = 1 004.62 al mes.
     */
    public function test_the_monthly_payment_matches_the_french_amortization_formula(): void
    {
        $payment = $this->calculator->monthlyPayment(
            Money::fromDecimalString('10000.00'),
            AnnualRate::fromPercentageString('36.00'),
            Term::fromMonths(12)
        );

        $this->assertSame('1004.62', $payment->toDecimalString());
    }

    public function test_without_interest_the_payment_is_the_principal_split_across_the_term(): void
    {
        $payment = $this->calculator->monthlyPayment(
            Money::fromDecimalString('12000.00'),
            AnnualRate::fromTenThousandths(0),
            Term::fromMonths(12)
        );

        $this->assertSame('1000.00', $payment->toDecimalString());
    }

    public function test_the_maximum_principal_is_the_inverse_of_the_payment(): void
    {
        $rate = AnnualRate::fromPercentageString('36.00');
        $term = Term::fromMonths(12);

        $principal = $this->calculator->maximumPrincipal(Money::fromDecimalString('1004.62'), $rate, $term);
        $payment = $this->calculator->monthlyPayment($principal, $rate, $term);

        // El capital se trunca a la baja, asi que el pago resultante nunca rebasa la
        // capacidad; la ida y vuelta cae un centavo por debajo del capital original
        // (9999.99) porque el pago de partida ya venia redondeado hacia arriba.
        $this->assertFalse($payment->isGreaterThan(Money::fromDecimalString('1004.62')));
        $this->assertSame('9999.99', $principal->toDecimalString());
    }

    public function test_the_effective_annual_rate_is_above_the_nominal_one(): void
    {
        // (1 + 0.03)^12 - 1 = 42.576089 % efectivo para una nominal de 36 %.
        // Se guarda en diezmilesimas redondeando al mas cercano: 4258 -> 42.58 %.
        $cat = $this->calculator->effectiveAnnualRate(AnnualRate::fromPercentageString('36.00'));

        $this->assertSame(4258, $cat->tenThousandths);
        $this->assertSame('42.58', $cat->toPercentageString());
    }

    public function test_a_zero_principal_produces_a_zero_payment(): void
    {
        $payment = $this->calculator->monthlyPayment(
            Money::zero(),
            AnnualRate::fromPercentageString('36.00'),
            Term::fromMonths(12)
        );

        $this->assertTrue($payment->isZero());
    }
}
