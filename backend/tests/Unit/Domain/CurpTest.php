<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidCurpException;
use App\Domain\Identity\Curp;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ====================== vectores fijos de CURP validas ==================

    /**
     * Vectores reales y variados, anadidos tras VUL-17.
     *
     * Hasta entonces este archivo tenia CUATRO vectores y los cuatro eran
     * variaciones del mismo caso —`HEGG5604…`, la misma entidad, el mismo sexo,
     * la misma forma de homoclave—. Con una sola familia, la bateria acredita
     * mucho menos de lo que parece: cualquier defecto sensible a la entidad
     * federativa, al sexo o a la homoclave alfabetica de los nacidos a partir
     * del 2000 pasaria entero.
     *
     * `HEGR791216HTCRRG09` es el vector que reporto el usuario. Queda fijo
     * aunque el defecto de VUL-17 no estuviera en `Curp`: fue la cadena con la
     * que se demostro que el validador NO era el culpable, y conviene que siga
     * demostrandolo si alguien toca el algoritmo.
     *
     * @return array<string, array{string}>
     */
    public static function validCurps(): array
    {
        return [
            // El vector de VUL-17: Tlaxcala, hombre, homoclave numerica.
            'HEGR — Tlaxcala, hombre, nacido antes del 2000' => ['HEGR791216HTCRRG09'],
            // Distrito Federal, mujer, homoclave alfabetica (nacida tras 2000).
            'PELA — CDMX, mujer, homoclave alfabetica' => ['PELA920323MDFRPNL4'],
            'MAAL — CDMX, hombre, homoclave alfabetica' => ['MAAL880712HDFRPNC4'],
            'ROMA — CDMX, hombre, homoclave alfabetica' => ['ROMA910517HDFDRNB7'],
            'LOPE — CDMX, mujer, homoclave alfabetica' => ['LOPE880322MDFPRZC3'],
            // Nacido en el extranjero: prefijo XEXX y entidad NE.
            'XEXX — nacido en el extranjero, entidad NE' => ['XEXX010101HNEXXXA4'],
            // Los que ya habia, conservados: ninguna entrada se pierde.
            'HEGG — Veracruz, mujer (vector historico)' => ['HEGG560427MVZRRL04'],
        ];
    }

    #[DataProvider('validCurps')]
    public function test_a_valid_curp_is_accepted(string $value): void
    {
        $curp = Curp::fromString($value);

        $this->assertSame($value, $curp->value);
        // El digito verificador que calcula el algoritmo es el que trae.
        $this->assertSame($value[17], Curp::checkDigit($value));
    }

    #[DataProvider('validCurps')]
    public function test_a_valid_curp_never_travels_complete_when_masked(string $value): void
    {
        // Regla 1 sobre cada vector, no solo sobre uno: el enmascarado tiene que
        // sostenerse para todas las formas, no para la familia que se probo.
        $masked = Curp::fromString($value)->masked();

        $this->assertNotSame($value, $masked);
        $this->assertStringNotContainsString($value, $masked);
        $this->assertStringStartsWith(substr($value, 0, 4), $masked);
    }

    #[DataProvider('validCurps')]
    public function test_flipping_the_check_digit_is_always_rejected(string $value): void
    {
        // Regresion del propio algoritmo: si dejara de comprobar el digito,
        // estas siete pasarian a aceptarse y ninguna otra prueba lo notaria.
        $wrongDigit = (string) (((int) $value[17] + 1) % 10);
        $broken = substr($value, 0, 17).$wrongDigit;

        $this->expectException(InvalidCurpException::class);
        Curp::fromString($broken);
    }
}
