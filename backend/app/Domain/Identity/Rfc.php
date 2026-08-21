<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Exception\InvalidRfcException;

/**
 * RFC con homoclave y DIGITO VERIFICADOR comprobado.
 *
 * Persona fisica: 4 letras + 6 digitos de fecha + 3 de homoclave (13 caracteres).
 * Persona moral : 3 letras + 6 digitos de fecha + 3 de homoclave (12 caracteres).
 *
 * Algoritmo del digito verificador (SAT):
 *   1. Si el RFC tiene 12 caracteres se antepone un espacio para trabajar con 13.
 *   2. Cada uno de los 12 primeros caracteres vale segun la tabla oficial
 *      (0-9 = 0..9, A..N = 10..23, & = 24, O..Z = 25..36, espacio = 37, enie = 38).
 *   3. Se multiplica cada valor por su factor posicional, de 13 hasta 2.
 *   4. residuo = suma mod 11; digito = 0 si el residuo es 0, 'A' si es 1,
 *      y 11 - residuo en cualquier otro caso.
 */
final readonly class Rfc
{
    private const VALUES = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
        '8' => 8, '9' => 9, 'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14,
        'F' => 15, 'G' => 16, 'H' => 17, 'I' => 18, 'J' => 19, 'K' => 20, 'L' => 21,
        'M' => 22, 'N' => 23, '&' => 24, 'O' => 25, 'P' => 26, 'Q' => 27, 'R' => 28,
        'S' => 29, 'T' => 30, 'U' => 31, 'V' => 32, 'W' => 33, 'X' => 34, 'Y' => 35,
        'Z' => 36, ' ' => 37, self::N_TILDE => 38,
    ];

    /**
     * La enie se maneja internamente como un solo byte. El RFC se indexa por
     * posicion y en UTF-8 la enie ocupa dos bytes: sin esta normalizacion, un RFC
     * con enie descuadraria tanto la longitud como los factores posicionales.
     */
    private const N_TILDE = "\xD1";

    /**
     * RFC genericos del SAT. No identifican a una persona y ademas no satisfacen el
     * digito verificador, asi que se rechazan de forma explicita: un credito no puede
     * originarse contra un contribuyente generico.
     */
    private const GENERIC = ['XAXX010101000', 'XEXX010101000'];

    private function __construct(public string $value, public bool $isLegalEntity) {}

    public static function fromString(string $value): self
    {
        $rfc = str_replace(['ñ', 'Ñ'], self::N_TILDE, trim($value));
        $rfc = strtoupper(str_replace(['-', ' '], '', $rfc));

        if (in_array($rfc, self::GENERIC, true)) {
            throw new InvalidRfcException('El RFC generico del SAT no identifica a una persona.');
        }

        $isLegalEntity = strlen($rfc) === 12;

        if (! $isLegalEntity && strlen($rfc) !== 13) {
            throw new InvalidRfcException('El RFC debe tener 12 caracteres (moral) o 13 (fisica).');
        }

        $letters = $isLegalEntity ? 3 : 4;
        $pattern = sprintf('/^[A-Z&\xD1]{%d}\d{6}[A-Z0-9]{3}$/', $letters);

        if (preg_match($pattern, $rfc) !== 1) {
            throw new InvalidRfcException('El RFC no cumple la estructura oficial.');
        }

        if (! self::isValidDate(substr($rfc, $letters, 6))) {
            throw new InvalidRfcException('La fecha contenida en el RFC no existe.');
        }

        if (substr($rfc, -1) !== self::checkDigit($rfc)) {
            throw new InvalidRfcException('El digito verificador del RFC no coincide.');
        }

        return new self(str_replace(self::N_TILDE, 'Ñ', $rfc), $isLegalEntity);
    }

    /** Digito verificador esperado (ultimo caracter) para un RFC de 12 o 13. */
    public static function checkDigit(string $rfc): string
    {
        $rfc = str_replace(['ñ', 'Ñ'], self::N_TILDE, $rfc);
        $padded = strlen($rfc) === 12 ? ' '.$rfc : $rfc;
        $sum = 0;

        for ($position = 0; $position < 12; $position++) {
            $sum += (self::VALUES[$padded[$position]] ?? 0) * (13 - $position);
        }

        $remainder = $sum % 11;

        return match ($remainder) {
            0 => '0',
            1 => 'A',
            default => (string) (11 - $remainder),
        };
    }

    /** Enmascarado para pantalla; el RFC completo nunca va a logs ni a la bitacora. */
    public function masked(): string
    {
        return substr($this->value, 0, 4).str_repeat('*', strlen($this->value) - 6).substr($this->value, -2);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function isValidDate(string $yymmdd): bool
    {
        $year = (int) substr($yymmdd, 0, 2);
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);

        return checkdate($month, $day, 1900 + $year) || checkdate($month, $day, 2000 + $year);
    }
}
