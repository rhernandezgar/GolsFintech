<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * Parametros del motor de reglas (RF-05 y RF-06).
 *
 * TODAS LAS CIFRAS DE ESTA CLASE SON PARAMETROS DE NEGOCIO PROPUESTOS, SUJETOS A
 * VALIDACION DEL AREA DE RIESGOS. No son constantes tecnicas: cambiarlas no rompe
 * nada del algoritmo, y por eso estan reunidas en un solo lugar. La unica excepcion
 * es la tasa del credito personal, que si esta anclada a una fuente (ver mas abajo).
 *
 * Los documentos de las fases 1 a 3 exigen determinar tipo de credito y capacidad de
 * pago, pero no fijan umbrales, techos ni tasas, salvo lo que muestra el prototipo
 * de la pantalla P5.
 *
 * Aforo: el pago mensual nunca puede rebasar el 30 % del ingreso mensual validado.
 * Es el limite que impide que el motor autorice creditos indebidos (riesgo R-08).
 *
 * TASAS: la progresion es DESCENDENTE conforme mejora el perfil, que es como se
 * comporta el credito real. Un tramo de mejor perfil no puede salir mas caro que uno
 * peor, y el invariante del constructor lo impide por construccion:
 *
 *   microcredito 60.00 % — ingreso bajo y sin historial crediticio comprobable.
 *   personal     28.50 % — ANCLADA al prototipo P5 de las Fases 2 y 3, que documenta
 *                          35 000 MXN a 18 meses con tasa anual fija de 28.5 % y CAT
 *                          informativo de 32.4 %. Es el tramo que cubre 35 000 (el
 *                          techo del microcredito son 30 000), asi que esta cifra no
 *                          se cambia sin cambiar la entrega academica.
 *   negocio      24.00 % — ingreso mas alto y actividad economica declarada, es decir
 *                          el mejor perfil de los tres y por tanto la tasa mas baja.
 */
final readonly class CreditPolicy
{
    /**
     * @param  int  $paymentCapacityPercent  porcentaje del ingreso comprometible al mes
     * @param  array<string, Money>  $minimumMonthlyIncome  ingreso minimo por tipo de credito
     * @param  array<string, Money>  $maximumAmount  techo autorizado por tipo de credito
     * @param  array<string, AnnualRate>  $annualRate  tasa anual ordinaria por tipo de credito
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
        $this->assertRatesDecreaseAsTheProfileImproves();
    }

    /**
     * Invariante: microcredito > personal > negocio.
     *
     * Existe porque la primera version de esta politica tenia 60 / 36 / 42 y el tramo
     * de mejor perfil salia mas caro que el intermedio. Es un error de negocio que no
     * produce ninguna excepcion por si solo —el motor calcula igual— y que solo se
     * detecta leyendo las tres cifras juntas. Aqui falla de inmediato.
     */
    private function assertRatesDecreaseAsTheProfileImproves(): void
    {
        $ordered = [CreditType::Microcredit, CreditType::Personal, CreditType::Business];

        for ($i = 1; $i < count($ordered); $i++) {
            $better = $this->annualRateFor($ordered[$i]);
            $worse = $this->annualRateFor($ordered[$i - 1]);

            if ($better->tenThousandths >= $worse->tenThousandths) {
                throw new InvalidArgumentException(sprintf(
                    'Tasas no monotonas: %s (%s %%) no puede ser mayor o igual que %s (%s %%).',
                    $ordered[$i]->value,
                    $better->toPercentageString(),
                    $ordered[$i - 1]->value,
                    $worse->toPercentageString()
                ));
            }
        }
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
                CreditType::Personal->value => AnnualRate::fromPercentageString('28.50'),
                CreditType::Business->value => AnnualRate::fromPercentageString('24.00'),
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
