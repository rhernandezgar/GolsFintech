<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Exception\InvalidCurpException;
use DateTimeImmutable;

/**
 * CURP: 18 caracteres con estructura oficial y DIGITO VERIFICADOR comprobado.
 *
 * Verificar el digito no es un adorno: es lo que convierte la validacion en un
 * control real. Una CURP con estructura correcta pero digito equivocado pasaria la
 * captura y seria rechazada despues por RENAPO, ya con el prospecto dentro del
 * proceso. Aqui se detiene antes (Fase 3 §4.5).
 *
 * Algoritmo del digito verificador (RENAPO):
 *   1. Cada uno de los 17 primeros caracteres vale su indice en el alfabeto
 *      "0123456789ABCDEFGHIJKLMNNOPQRSTUVWXYZ" (con N con virgulilla en la posicion 24).
 *   2. Se multiplica cada valor por su factor posicional, de 18 hasta 2.
 *   3. digito = 10 - (suma mod 10); si el resultado es 10, el digito es 0.
 */
final readonly class Curp
{
    private const LENGTH = 18;

    /** Alfabeto posicional oficial; la enie ocupa el indice 24. */
    private const ALPHABET = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'Ñ',
        'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

    /** Claves de entidad federativa de la CURP, mas NE (nacido en el extranjero). */
    private const STATE_CODES = ['AS', 'BC', 'BS', 'CC', 'CH', 'CL', 'CM', 'CS', 'DF', 'DG',
        'GR', 'GT', 'HG', 'JC', 'MC', 'MN', 'MS', 'NE', 'NL', 'NT', 'OC', 'PL', 'QR', 'QT',
        'SL', 'SP', 'SR', 'TC', 'TL', 'TS', 'VZ', 'YN', 'ZS'];

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $curp = strtoupper(trim($value));

        if (strlen($curp) !== self::LENGTH) {
            throw new InvalidCurpException('La CURP debe tener exactamente 18 caracteres.');
        }

        // Estructura: 4 letras, fecha, sexo, entidad, 3 consonantes internas,
        // homoclave (digito para nacidos antes del 2000, letra a partir del 2000)
        // y digito verificador.
        $pattern = '/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{2}[B-DF-HJ-NP-TV-Z]{3}[0-9A-Z]\d$/';

        if (preg_match($pattern, $curp) !== 1) {
            throw new InvalidCurpException('La CURP no cumple la estructura oficial.');
        }

        if (! in_array(substr($curp, 11, 2), self::STATE_CODES, true)) {
            throw new InvalidCurpException('La clave de entidad federativa de la CURP no existe.');
        }

        if (! self::isValidBirthDate(substr($curp, 4, 6))) {
            throw new InvalidCurpException('La fecha de nacimiento contenida en la CURP no existe.');
        }

        if ($curp[17] !== self::checkDigit($curp)) {
            throw new InvalidCurpException('El digito verificador de la CURP no coincide.');
        }

        return new self($curp);
    }

    /** Digito verificador esperado para los primeros 17 caracteres. */
    public static function checkDigit(string $curp): string
    {
        $sum = 0;

        for ($position = 0; $position < 17; $position++) {
            $index = array_search($curp[$position], self::ALPHABET, true);
            $sum += ($index === false ? 0 : $index) * (18 - $position);
        }

        $digit = 10 - ($sum % 10);

        return (string) ($digit === 10 ? 0 : $digit);
    }

    /** Sexo declarado en la CURP: H, M o X. */
    public function sex(): string
    {
        return $this->value[10];
    }

    public function stateCode(): string
    {
        return substr($this->value, 11, 2);
    }

    /**
     * Enmascarado para pantalla. NUNCA se registra la CURP completa en bitacora ni
     * en logs (regla de seguridad 1); esto es lo unico que puede desplegarse.
     */
    public function masked(): string
    {
        return substr($this->value, 0, 4).str_repeat('*', 12).substr($this->value, -2);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function isValidBirthDate(string $yymmdd): bool
    {
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);
        $year = (int) substr($yymmdd, 0, 2);

        // El siglo no esta en la CURP; se prueban ambos y basta con que uno exista.
        foreach ([1900 + $year, 2000 + $year] as $fullYear) {
            if (checkdate($month, $day, $fullYear)) {
                return true;
            }
        }

        return false;
    }

    /** Fecha de nacimiento deducida, util para contrastarla con la edad declarada. */
    public function birthDate(?DateTimeImmutable $today = null): ?DateTimeImmutable
    {
        $today ??= new DateTimeImmutable;
        $yymmdd = substr($this->value, 4, 6);
        $year = (int) substr($yymmdd, 0, 2);

        foreach ([2000 + $year, 1900 + $year] as $fullYear) {
            $date = DateTimeImmutable::createFromFormat(
                'Y-m-d',
                sprintf('%04d-%s-%s', $fullYear, substr($yymmdd, 2, 2), substr($yymmdd, 4, 2))
            );

            if ($date instanceof DateTimeImmutable && $date <= $today) {
                return $date->setTime(0, 0);
            }
        }

        return null;
    }
}
