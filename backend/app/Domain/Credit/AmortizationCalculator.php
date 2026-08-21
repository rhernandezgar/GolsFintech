<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Shared\Money;

/**
 * Calculo de la anualidad (sistema frances: pago mensual constante).
 *
 *   pago = P * i * (1+i)^n / ((1+i)^n - 1)
 *
 * Se resuelve con bcmath a 18 decimales y el resultado se redondea UNA sola vez, al
 * centavo, para construir un Money. Nunca se opera con float: en un credito a 36
 * meses un error de redondeo se multiplica por 36 y termina siendo dinero real.
 */
final class AmortizationCalculator
{
    private const SCALE = 18;

    public function monthlyPayment(Money $principal, AnnualRate $rate, Term $term): Money
    {
        if (! $principal->isPositive()) {
            return Money::zero($principal->currency);
        }

        $monthlyRate = $this->monthlyRate($rate);

        // Sin intereses el pago es el capital repartido entre los meses del plazo.
        if (bccomp($monthlyRate, '0', self::SCALE) === 0) {
            return $principal->ratio(1, $term->months);
        }

        $growth = $this->growthFactor($monthlyRate, $term);

        $payment = bcdiv(
            bcmul(bcmul($principal->toDecimalString(), $monthlyRate, self::SCALE), $growth, self::SCALE),
            bcsub($growth, '1', self::SCALE),
            self::SCALE
        );

        return Money::fromDecimalString($this->roundToCents($payment), $principal->currency);
    }

    /**
     * Capital maximo cuyo pago mensual no rebasa la capacidad de pago. Es la formula
     * anterior despejada; se redondea a la BAJA para que el pago resultante nunca
     * quede por encima del aforo autorizado.
     */
    public function maximumPrincipal(Money $paymentCapacity, AnnualRate $rate, Term $term): Money
    {
        if (! $paymentCapacity->isPositive()) {
            return Money::zero($paymentCapacity->currency);
        }

        $monthlyRate = $this->monthlyRate($rate);

        if (bccomp($monthlyRate, '0', self::SCALE) === 0) {
            return $paymentCapacity->multipliedBy($term->months);
        }

        $growth = $this->growthFactor($monthlyRate, $term);

        $principal = bcdiv(
            bcmul($paymentCapacity->toDecimalString(), bcsub($growth, '1', self::SCALE), self::SCALE),
            bcmul($monthlyRate, $growth, self::SCALE),
            self::SCALE
        );

        return Money::fromDecimalString($this->truncateToCents($principal), $paymentCapacity->currency);
    }

    /**
     * CAT informativo: tasa efectiva anual equivalente, (1+i)^12 - 1. Se calcula solo
     * sobre intereses ordinarios, sin comisiones ni seguros, y por eso se presenta al
     * prospecto etiquetado como informativo (Fase 2 §P5).
     */
    public function effectiveAnnualRate(AnnualRate $rate): AnnualRate
    {
        $monthlyRate = $this->monthlyRate($rate);
        $annual = bcsub(bcpow(bcadd('1', $monthlyRate, self::SCALE), '12', self::SCALE), '1', self::SCALE);

        // De fraccion decimal a diezmilesimas enteras, redondeando al mas cercano.
        $tenThousandths = (int) $this->roundToScale(bcmul($annual, '10000', self::SCALE), 0);

        return AnnualRate::fromTenThousandths(min($tenThousandths, 99999));
    }

    private function monthlyRate(AnnualRate $rate): string
    {
        return bcdiv($rate->toDecimalString(), '12', self::SCALE);
    }

    private function growthFactor(string $monthlyRate, Term $term): string
    {
        return bcpow(bcadd('1', $monthlyRate, self::SCALE), (string) $term->months, self::SCALE);
    }

    private function roundToCents(string $value): string
    {
        return $this->roundToScale($value, 2);
    }

    /** Redondeo mitad hacia arriba hecho con bcmath, sin convertir a float. */
    private function roundToScale(string $value, int $scale): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1), 0), self::SCALE);

        return bcadd($value, $half, $scale);
    }

    private function truncateToCents(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
