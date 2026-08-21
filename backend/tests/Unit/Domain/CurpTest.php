<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidCurpException;
use App\Domain\Identity\Curp;
use PHPUnit\Framework\TestCase;

/**
 * El vector HEGG560427MVZRRL04 es la CURP de ejemplo publicada por RENAPO; sirve
 * como comprobacion independiente del algoritmo del digito verificador.
 */
final class CurpTest extends TestCase
{
    private const VALID = 'HEGG560427MVZRRL04';

    public function test_it_accepts_a_curp_with_a_correct_check_digit(): void
    {
        $curp = Curp::fromString(self::VALID);

        $this->assertSame(self::VALID, $curp->value);
        $this->assertSame('M', $curp->sex());
        $this->assertSame('VZ', $curp->stateCode());
    }

    public function test_it_normalizes_lowercase_and_surrounding_spaces(): void
    {
        $this->assertSame(self::VALID, Curp::fromString('  hegg560427mvzrrl04 ')->value);
    }

    public function test_it_rejects_a_wrong_check_digit(): void
    {
        // Estructura impecable, digito verificador equivocado: es el caso que una
        // validacion de solo formato dejaria pasar hasta el rechazo de RENAPO.
        $this->expectException(InvalidCurpException::class);

        Curp::fromString('HEGG560427MVZRRL05');
    }

    public function test_it_computes_the_expected_check_digit(): void
    {
        $this->assertSame('4', Curp::checkDigit(self::VALID));
    }

    public function test_it_rejects_a_wrong_length(): void
    {
        $this->expectException(InvalidCurpException::class);

        Curp::fromString('HEGG560427MVZRRL0');
    }

    public function test_it_rejects_an_unknown_state_code(): void
    {
        $this->expectException(InvalidCurpException::class);

        // ZZ no es clave de entidad federativa.
        Curp::fromString('HEGG560427MZZRRL00');
    }

    public function test_it_rejects_an_impossible_birth_date(): void
    {
        $this->expectException(InvalidCurpException::class);

        // 31 de febrero.
        Curp::fromString('HEGG560231MVZRRL00');
    }

    public function test_it_rejects_a_malformed_structure(): void
    {
        $this->expectException(InvalidCurpException::class);

        Curp::fromString('1EGG560427MVZRRL04');
    }

    public function test_the_masked_form_hides_the_identifier(): void
    {
        $masked = Curp::fromString(self::VALID)->masked();

        $this->assertStringNotContainsString('560427', $masked);
        $this->assertSame(18, strlen($masked));
    }
}
