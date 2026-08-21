<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Exception\InvalidMoneyException;

/**
 * Tasa anual ordinaria. Se guarda en diezmilesimas como ENTERO (3600 = 36.0000 %),
 * que es justo la precision de la columna decimal(6,4): igual que con el dinero, la
 * tasa no se representa en coma flotante.
 */
final readonly class AnnualRate
{
    private const SCALE = 10000;

    private function __construct(public int $tenThousandths)
    {
    }

    public static function fromTenThousandths(int $tenThousandths): self
    {
        if ($tenThousandths < 0 || $tenThousandths > 99999) {
            throw new InvalidMoneyException('Tasa anual fuera de rango.');
        }

        return new self($tenThousandths);
    }

    /**
     * Acepta la forma en que la guarda la base de datos: '0.3600'. Se convierte con
     * aritmetica entera, sin pasar por float, por la misma razon que Money.
     */
    public static function fromDecimalString(string $rate): self
    {
        if (preg_match('/^(?<units>\d+)(?:\.(?<fraction>\d{1,4}))?$/', trim($rate), $m) !== 1) {
            throw new InvalidMoneyException(sprintf('Tasa mal formada: "%s".', $rate));
        }

        return self::fromTenThousandths((int) $m['units'] * self::SCALE + (int) str_pad($m['fraction'] ?? '', 4, '0'));
    }

    /** Acepta el porcentaje tal como se captura: '36' o '36.50'. */
    public static function fromPercentageString(string $percentage): self
    {
        if (preg_match('/^(?<units>\d+)(?:\.(?<fraction>\d{1,2}))?$/', trim($percentage), $m) !== 1) {
            throw new InvalidMoneyException(sprintf('Porcentaje mal formado: "%s".', $percentage));
        }

        return self::fromTenThousandths((int) $m['units'] * 100 + (int) str_pad($m['fraction'] ?? '', 2, '0'));
    }

    /** Tasa anual como fraccion decimal para bcmath: 3600 -> '0.3600'. */
    public function toDecimalString(): string
    {
        return sprintf('%d.%04d', intdiv($this->tenThousandths, self::SCALE), $this->tenThousandths % self::SCALE);
    }

    /** Porcentaje para mostrar al prospecto: 3600 -> '36.00'. */
    public function toPercentageString(): string
    {
        return sprintf('%d.%02d', intdiv($this->tenThousandths, 100), $this->tenThousandths % 100);
    }

    public function isZero(): bool
    {
        return $this->tenThousandths === 0;
    }
}
