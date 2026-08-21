<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidRfcException;
use App\Domain\Identity\Rfc;
use PHPUnit\Framework\TestCase;

/**
 * Vectores reales para comprobar el digito verificador de forma independiente:
 *   SAT970701NN3  -> RFC del propio SAT (persona moral).
 *   GODE561231GR8 -> RFC de pruebas del CFDI (persona fisica).
 */
final class RfcTest extends TestCase
{
    public function test_it_accepts_a_legal_entity_rfc(): void
    {
        $rfc = Rfc::fromString('SAT970701NN3');

        $this->assertSame('SAT970701NN3', $rfc->value);
        $this->assertTrue($rfc->isLegalEntity);
    }

    public function test_it_accepts_a_natural_person_rfc(): void
    {
        $rfc = Rfc::fromString('GODE561231GR8');

        $this->assertSame('GODE561231GR8', $rfc->value);
        $this->assertFalse($rfc->isLegalEntity);
    }

    public function test_it_computes_the_expected_check_digit(): void
    {
        $this->assertSame('3', Rfc::checkDigit('SAT970701NN3'));
        $this->assertSame('8', Rfc::checkDigit('GODE561231GR8'));
    }

    public function test_it_rejects_a_wrong_check_digit(): void
    {
        $this->expectException(InvalidRfcException::class);

        Rfc::fromString('GODE561231GR7');
    }

    public function test_it_rejects_the_generic_rfc(): void
    {
        // El RFC generico no identifica a nadie: no puede originarse un credito con el.
        $this->expectException(InvalidRfcException::class);

        Rfc::fromString('XAXX010101000');
    }

    public function test_it_rejects_an_impossible_date(): void
    {
        $this->expectException(InvalidRfcException::class);

        Rfc::fromString('GODE561331GR8');
    }

    public function test_it_rejects_a_wrong_length(): void
    {
        $this->expectException(InvalidRfcException::class);

        Rfc::fromString('GODE561231G');
    }

    public function test_the_masked_form_hides_the_identifier(): void
    {
        $this->assertStringNotContainsString('561231', Rfc::fromString('GODE561231GR8')->masked());
    }
}
