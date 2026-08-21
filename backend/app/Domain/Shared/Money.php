<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Exception\InvalidMoneyException;

/**
 * Importe monetario. Guarda CENTAVOS como entero, nunca un flotante.
 *
 * Es dinero: en un motor de credito un error de redondeo de coma flotante se
 * convierte en un pago mal calculado, y eso no es aceptable. Toda la aritmetica de
 * esta clase es entera; el unico punto donde entra una cadena decimal es en la
 * conversion de entrada y salida (la base de datos guarda decimal(15,2)).
 *
 * Los importes negativos se permiten para poder restar y comparar, pero las reglas
 * de credito rechazan explicitamente los montos no positivos donde corresponde.
 */
final readonly class Money
{
    private const SCALE = 2;

    private function __construct(public int $cents, public string $currency)
    {
    }

    public static function fromCents(int $cents, string $currency = 'MXN'): self
    {
        return new self($cents, self::normalizeCurrency($currency));
    }

    /**
     * Convierte una cadena decimal ('1500.50', '-20', '0.05') a centavos.
     * Se recibe cadena y no float justamente para no heredar el error del binario.
     */
    public static function fromDecimalString(string $amount, string $currency = 'MXN'): self
    {
        $amount = trim($amount);

        if (preg_match('/^(?<sign>[+-]?)(?<units>\d+)(?:\.(?<fraction>\d{1,2}))?$/', $amount, $m) !== 1) {
            throw new InvalidMoneyException(sprintf('Importe mal formado: "%s".', $amount));
        }

        $fraction = str_pad($m['fraction'] ?? '', self::SCALE, '0');
        $cents = (int) $m['units'] * 100 + (int) $fraction;

        return new self($m['sign'] === '-' ? -$cents : $cents, self::normalizeCurrency($currency));
    }

    public static function zero(string $currency = 'MXN'): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->cents * $factor, $this->currency);
    }

    /**
     * Proporcion exacta en aritmetica entera, con redondeo al centavo mas cercano
     * (mitad hacia arriba en valor absoluto). Se usa, por ejemplo, para el aforo:
     * ratio(30, 100) es el 30 % del ingreso mensual.
     */
    public function ratio(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidMoneyException('Division entre cero al calcular una proporcion monetaria.');
        }

        $product = $this->cents * $numerator;
        $sign = ($product < 0) === ($denominator < 0) ? 1 : -1;
        $absolute = intdiv(abs($product) * 2 + abs($denominator), abs($denominator) * 2);

        return new self($sign * $absolute, $this->currency);
    }

    /**
     * Trunca hacia abajo a un multiplo de $step unidades mayores (pesos completos).
     * El monto ofrecido se redondea a la baja para no rebasar nunca la capacidad
     * de pago calculada.
     */
    public function floorToMajorUnits(int $step = 100): self
    {
        if ($step <= 0) {
            throw new InvalidMoneyException('El escalon de redondeo debe ser positivo.');
        }

        $stepInCents = $step * 100;

        return new self(intdiv($this->cents, $stepInCents) * $stepInCents, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents >= $other->cents;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents < $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->cents === $other->cents;
    }

    public static function min(self $a, self $b): self
    {
        return $a->isLessThan($b) ? $a : $b;
    }

    /** Representacion para persistir en decimal(15,2) y para calcular con bcmath. */
    public function toDecimalString(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $absolute = abs($this->cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidMoneyException(
                sprintf('No se pueden operar importes en %s y %s.', $this->currency, $other->currency)
            );
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidMoneyException(sprintf('Codigo de moneda invalido: "%s".', $currency));
        }

        return $currency;
    }
}
