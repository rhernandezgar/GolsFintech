<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidMoneyException;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * Money guarda centavos como entero. Estas pruebas fijan esa garantia: si alguien
 * la cambiara por un flotante, varias de ellas fallarian.
 */
final class MoneyTest extends TestCase
{
    public function test_it_stores_cents_as_an_integer(): void
    {
        $money = Money::fromDecimalString('1500.50');

        $this->assertSame(150050, $money->cents);
        $this->assertIsInt($money->cents);
        $this->assertSame('1500.50', $money->toDecimalString());
    }

    public function test_it_parses_amounts_without_a_fractional_part(): void
    {
        $this->assertSame(300000, Money::fromDecimalString('3000')->cents);
        $this->assertSame(5, Money::fromDecimalString('0.05')->cents);
        $this->assertSame(-2550, Money::fromDecimalString('-25.50')->cents);
    }

    public function test_it_rejects_a_malformed_amount(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::fromDecimalString('1,500.00');
    }

    public function test_it_rejects_more_than_two_decimals(): void
    {
        // 0.005 no existe como importe: aceptarlo obligaria a redondear en silencio.
        $this->expectException(InvalidMoneyException::class);

        Money::fromDecimalString('10.005');
    }

    public function test_the_classic_floating_point_sum_stays_exact(): void
    {
        // 0.1 + 0.2 en coma flotante no da 0.3; en centavos enteros, si.
        $sum = Money::fromDecimalString('0.10')->add(Money::fromDecimalString('0.20'));

        $this->assertSame(30, $sum->cents);
        $this->assertSame('0.30', $sum->toDecimalString());
    }

    public function test_a_hundred_additions_of_one_cent_make_exactly_one_peso(): void
    {
        $total = Money::zero();

        for ($i = 0; $i < 100; $i++) {
            $total = $total->add(Money::fromCents(1));
        }

        $this->assertSame('1.00', $total->toDecimalString());
    }

    public function test_it_refuses_to_operate_on_different_currencies(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::fromCents(100, 'MXN')->add(Money::fromCents(100, 'USD'));
    }

    public function test_the_ratio_rounds_half_up_to_the_nearest_cent(): void
    {
        // 30 % de 8333.33 = 2499.999 -> 2500.00
        $this->assertSame(250000, Money::fromDecimalString('8333.33')->ratio(30, 100)->cents);
        // 30 % de 3000.05 = 900.015 -> 900.02
        $this->assertSame(90002, Money::fromDecimalString('3000.05')->ratio(30, 100)->cents);
    }

    public function test_it_floors_to_multiples_of_one_hundred_major_units(): void
    {
        $this->assertSame('12300.00', Money::fromDecimalString('12399.99')->floorToMajorUnits(100)->toDecimalString());
        $this->assertSame('0.00', Money::fromDecimalString('99.99')->floorToMajorUnits(100)->toDecimalString());
    }

    public function test_comparisons(): void
    {
        $small = Money::fromDecimalString('100.00');
        $big = Money::fromDecimalString('200.00');

        $this->assertTrue($big->isGreaterThan($small));
        $this->assertTrue($small->isLessThan($big));
        $this->assertTrue($small->equals(Money::fromCents(10000)));
        $this->assertSame($small, Money::min($small, $big));
        $this->assertTrue(Money::zero()->isZero());
    }
}
