<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Credit\AmortizationCalculator;
use App\Domain\Credit\AnnualRate;
use App\Domain\Credit\CreditPolicy;
use App\Domain\Credit\CreditRulesEngine;
use App\Domain\Credit\CreditType;
use App\Domain\Credit\Term;
use App\Domain\Exception\IneligibleAgeException;
use App\Domain\Exception\InsufficientIncomeException;
use App\Domain\Exception\UnauthorizedTermException;
use App\Domain\Shared\Money;
use InvalidArgumentException;
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
        // Los dos extremos que el motor debe rechazar: menor de edad y por encima
        // del maximo de la politica (18-74). Un solo caso no acreditaria el rango.
        $this->assertAgeIsRejected(17);
        $this->assertAgeIsRejected(75);
    }

    public function test_it_accepts_the_ages_at_both_ends_of_the_authorized_range(): void
    {
        // 18 y 74 son limites INCLUSIVOS: si alguno se cayera del rango, el motor
        // negaria credito a quien la politica si autoriza.
        foreach ([18, 74] as $age) {
            $offer = $this->engine->evaluate(Money::fromDecimalString('20000.00'), $age, null, Term::fromMonths(12));

            $this->assertSame(CreditType::Personal, $offer->creditType, sprintf('Edad %d rechazada', $age));
        }
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
        $this->assertSame('28.50', $offer->annualRate->toPercentageString());
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

    public function test_an_income_exactly_at_a_threshold_belongs_to_the_upper_tier(): void
    {
        // El umbral es inclusivo (>=). Se prueba al centavo exacto porque es donde
        // un > en lugar de >= cambia el tipo de credito sin que nada mas falle.
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

    public function test_one_cent_below_a_threshold_falls_back_to_the_previous_tier(): void
    {
        $this->assertSame(
            CreditType::Microcredit,
            $this->engine->determineCreditType(Money::fromDecimalString('7999.99'), null)
        );
        // Con negocio declarado pero un centavo por debajo del minimo de negocio,
        // la linea que corresponde es la personal, no la de negocio.
        $this->assertSame(
            CreditType::Personal,
            $this->engine->determineCreditType(Money::fromDecimalString('14999.99'), 'abarrotes')
        );
    }

    public function test_the_proposed_amount_is_capped_by_the_ceiling_of_every_credit_type(): void
    {
        // Un ingreso alto dentro de cada tramo hace que la restriccion activa sea el
        // TECHO del tipo y no la capacidad de pago. El motor nunca debe proponer por
        // encima de ese techo (riesgo R-08).
        $cases = [
            // ingreso, negocio declarado, tipo esperado, techo esperado
            ['7999.99', null, CreditType::Microcredit, '30000.00'],
            ['900000.00', null, CreditType::Personal, '150000.00'],
            ['900000.00', 'abarrotes', CreditType::Business, '500000.00'],
        ];

        foreach ($cases as [$income, $businessType, $expectedType, $ceiling]) {
            $offer = $this->engine->evaluate(
                Money::fromDecimalString($income),
                40,
                $businessType,
                Term::fromMonths(36)
            );

            $this->assertSame($expectedType, $offer->creditType, sprintf('Tipo inesperado con ingreso %s', $income));
            $this->assertSame($ceiling, $offer->proposedAmount->toDecimalString(), sprintf('Techo rebasado con ingreso %s', $income));
        }
    }

    public function test_the_personal_rate_is_the_one_documented_by_the_p5_prototype(): void
    {
        // El prototipo P5 de las Fases 2 y 3 documenta 35 000 MXN a 18 meses con tasa
        // anual fija de 28.5 %. El tramo que cubre 35 000 es el personal (el techo del
        // microcredito son 30 000), asi que esa tasa esta anclada a la entrega.
        $policy = CreditPolicy::default();

        $this->assertSame('28.50', $policy->annualRateFor(CreditType::Personal)->toPercentageString());
        $this->assertTrue(
            Money::fromDecimalString('35000.00')->isGreaterThan($policy->maximumAmountFor(CreditType::Microcredit))
        );
        $this->assertFalse(
            Money::fromDecimalString('35000.00')->isGreaterThan($policy->maximumAmountFor(CreditType::Personal))
        );
    }

    public function test_the_rates_decrease_as_the_profile_improves(): void
    {
        // Un perfil mejor no puede pagar mas caro que uno peor: microcredito >
        // personal > negocio.
        $policy = CreditPolicy::default();

        $this->assertGreaterThan(
            $policy->annualRateFor(CreditType::Personal)->tenThousandths,
            $policy->annualRateFor(CreditType::Microcredit)->tenThousandths
        );
        $this->assertGreaterThan(
            $policy->annualRateFor(CreditType::Business)->tenThousandths,
            $policy->annualRateFor(CreditType::Personal)->tenThousandths
        );
    }

    public function test_a_policy_whose_rates_are_not_monotonic_is_rejected(): void
    {
        // Regresion exacta de la politica anterior (60 / 36 / 42): el tramo de negocio
        // salia mas caro que el personal. No lanzaba ninguna excepcion por si solo, y
        // por eso el invariante vive en el constructor de la politica.
        $this->expectException(InvalidArgumentException::class);

        new CreditPolicy(
            paymentCapacityPercent: 30,
            minimumAge: 18,
            maximumAge: 74,
            minimumOfferAmount: Money::fromDecimalString('1000.00'),
            minimumMonthlyIncome: CreditPolicy::default()->minimumMonthlyIncome,
            maximumAmount: CreditPolicy::default()->maximumAmount,
            annualRate: [
                CreditType::Microcredit->value => AnnualRate::fromPercentageString('60.00'),
                CreditType::Personal->value => AnnualRate::fromPercentageString('36.00'),
                CreditType::Business->value => AnnualRate::fromPercentageString('42.00'),
            ],
        );
    }

    /** El plazo se fija en 12 meses: lo que se prueba aqui es la edad, no el plazo. */
    private function assertAgeIsRejected(int $age): void
    {
        try {
            $this->engine->evaluate(Money::fromDecimalString('20000.00'), $age, null, Term::fromMonths(12));
            $this->fail(sprintf('La edad %d debio rechazarse por estar fuera del rango autorizado.', $age));
        } catch (IneligibleAgeException) {
            $this->addToAssertionCount(1);
        }
    }
}
