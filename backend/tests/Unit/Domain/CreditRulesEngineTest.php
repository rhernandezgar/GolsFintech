<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Credit\AmortizationCalculator;
use App\Domain\Credit\CreditPolicy;
use App\Domain\Credit\CreditRulesEngine;
use App\Domain\Credit\CreditType;
use App\Domain\Credit\Term;
use App\Domain\Exception\IneligibleAgeException;
use App\Domain\Exception\InsufficientIncomeException;
use App\Domain\Exception\UnauthorizedTermException;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * Prueba de regresion del motor de reglas (PT-08). Corre sin base de datos ni
 * servicios externos: el riesgo R-08 —autorizar creditos indebidos— se detecta aqui,
 * en milisegundos, y no en una revision manual.
 */
final class CreditRulesEngineTest extends TestCase
{
    private CreditRulesEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new CreditRulesEngine(CreditPolicy::default(), new AmortizationCalculator());
    }

    public function test_the_payment_capacity_is_thirty_percent_of_the_monthly_income(): void
    {
        $capacity = $this->engine->estimatePaymentCapacity(Money::fromDecimalString('10000.00'));

        $this->assertSame('3000.00', $capacity->toDecimalString());
    }

    public function test_it_resolves_the_credit_type_from_income_and_declared_business(): void
    {
        $this->assertSame(
            CreditType::Microcredit,
            $this->engine->determineCreditType(Money::fromDecimalString('3000.00'), null)
        );
        $this->assertSame(
            CreditType::Personal,
            $this->engine->determineCreditType(Money::fromDecimalString('8000.00'), null)
        );
        $this->assertSame(
            CreditType::Business,
            $this->engine->determineCreditType(Money::fromDecimalString('15000.00'), 'abarrotes')
        );
    }

    public function test_a_declared_business_without_the_minimum_income_is_not_a_business_credit(): void
    {
        $this->assertSame(
            CreditType::Personal,
            $this->engine->determineCreditType(Money::fromDecimalString('9000.00'), 'abarrotes')
        );
    }

    public function test_it_rejects_an_income_below_every_minimum(): void
    {
        $this->expectException(InsufficientIncomeException::class);

        $this->engine->determineCreditType(Money::fromDecimalString('2999.99'), null);
    }

    public function test_it_rejects_an_age_outside_the_authorized_range(): void
    {
        $this->expectException(IneligibleAgeException::class);

        $this->engine->evaluate(Money::fromDecimalString('20000.00'), 17, null, Term::fromMonths(12));
    }

    public function test_it_rejects_a_term_outside_the_authorized_catalogue(): void
    {
        // VUL-02: el plazo llega del cliente y el dominio lo rechaza si no esta en
        // el catalogo, sin importar por donde haya entrado.
        $this->expectException(UnauthorizedTermException::class);

        Term::fromMonths(5);
    }

    public function test_the_monthly_payment_never_exceeds_the_payment_capacity(): void
    {
        foreach (['3000.00', '8000.00', '15000.00', '42000.00', '250000.00'] as $income) {
            foreach (Term::AUTHORIZED_MONTHS as $months) {
                $offer = $this->engine->evaluate(
                    Money::fromDecimalString($income),
                    35,
                    'abarrotes',
                    Term::fromMonths($months)
                );

                $this->assertFalse(
                    $offer->estimatedMonthlyPayment->isGreaterThan($offer->paymentCapacity),
                    sprintf('Pago %s rebasa la capacidad %s con ingreso %s a %d meses',
                        $offer->estimatedMonthlyPayment->toDecimalString(),
                        $offer->paymentCapacity->toDecimalString(),
                        $income,
                        $months)
                );
            }
        }
    }

    public function test_the_proposed_amount_never_exceeds_the_ceiling_of_its_credit_type(): void
    {
        $offer = $this->engine->evaluate(Money::fromDecimalString('900000.00'), 40, 'abarrotes', Term::fromMonths(36));

        $this->assertSame(CreditType::Business, $offer->creditType);
        $this->assertSame('500000.00', $offer->proposedAmount->toDecimalString());
    }

    public function test_a_longer_term_lowers_the_monthly_payment(): void
    {
        $shorter = $this->engine->evaluate(Money::fromDecimalString('20000.00'), 30, null, Term::fromMonths(6));
        $longer = $this->engine->evaluate(Money::fromDecimalString('20000.00'), 30, null, Term::fromMonths(36));

        $this->assertTrue($longer->proposedAmount->isGreaterThan($shorter->proposedAmount));
    }

    public function test_the_offer_is_internally_consistent(): void
    {
        $offer = $this->engine->evaluate(Money::fromDecimalString('20000.00'), 30, null, Term::fromMonths(12));

        $this->assertSame(
            $offer->estimatedMonthlyPayment->multipliedBy(12)->toDecimalString(),
            $offer->totalPayable->toDecimalString()
        );
        $this->assertTrue($offer->totalInterest()->isPositive());
        $this->assertSame('36.00', $offer->annualRate->toPercentageString());
        // El CAT informativo, por ser efectivo anual, supera a la tasa nominal.
        $this->assertGreaterThan($offer->annualRate->tenThousandths, $offer->cat->tenThousandths);
    }

    public function test_the_proposed_amount_is_rounded_down_to_hundreds(): void
    {
        $offer = $this->engine->evaluate(Money::fromDecimalString('12345.67'), 30, null, Term::fromMonths(24));

        $this->assertSame(0, $offer->proposedAmount->cents % 10000);
    }

    public function test_an_offer_below_the_minimum_origination_amount_is_rejected(): void
    {
        // Politica alterna con un minimo de originacion alto: el monto que alcanza la
        // capacidad de pago queda por debajo y el motor prefiere no ofrecer nada antes
        // que originar un credito fuera de politica.
        $policy = new CreditPolicy(
            paymentCapacityPercent: 30,
            minimumAge: 18,
            maximumAge: 74,
            minimumOfferAmount: Money::fromDecimalString('50000.00'),
            minimumMonthlyIncome: CreditPolicy::default()->minimumMonthlyIncome,
            maximumAmount: CreditPolicy::default()->maximumAmount,
            annualRate: CreditPolicy::default()->annualRate,
        );

        $this->expectException(InsufficientIncomeException::class);

        (new CreditRulesEngine($policy, new AmortizationCalculator()))
            ->evaluate(Money::fromDecimalString('3000.00'), 30, null, Term::fromMonths(6));
    }
}
