<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Enmascara datos sensibles ANTES de que el evento se escriba (VUL-04, CWE-532).
 *
 * El control esta en el dominio y no en el adaptador a proposito: asi ningun punto
 * del sistema puede escribir en la bitacora una CURP, un RFC o un numero de tarjeta
 * en claro, ni por descuido ni por un metadato copiado tal cual desde el proveedor.
 * Se enmascara por dos vias complementarias:
 *   - por nombre de la clave (curp, rfc, card_number, password, token...);
 *   - por forma del valor, aunque la clave se llame de otro modo.
 */
final class SensitiveDataMasker
{
    private const REDACTED = '[REDACTED]';

    /** Claves cuyo valor nunca se escribe, sin importar su contenido. */
    private const SENSITIVE_KEYS = ['curp', 'rfc', 'card_number', 'pan', 'cvv', 'password',
        'secret', 'token', 'authorization', 'api_key', 'access_token'];

    /**
     * @param array<array-key, mixed> $metadata
     * @return array<array-key, mixed>
     */
    public function mask(array $metadata): array
    {
        $masked = [];

        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->mask($value);

                continue;
            }

            // Un booleano no puede transportar un identificador: 'has_rfc' => true
            // es informacion util para auditar y no un dato personal. Todo lo demas
            // bajo una clave sensible se redacta, incluidos los numeros.
            if (is_string($key) && $this->isSensitiveKey($key) && ! is_bool($value)) {
                $masked[$key] = self::REDACTED;

                continue;
            }

            $masked[$key] = is_string($value) ? $this->maskValue($value) : $value;
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /** Enmascara por forma: CURP, RFC y secuencias con pinta de numero de tarjeta. */
    private function maskValue(string $value): string
    {
        $patterns = [
            // CURP completa.
            '/\b[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{2}[B-DF-HJ-NP-TV-Z]{3}[0-9A-Z]\d\b/i',
            // RFC de persona fisica o moral.
            '/\b[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}\b/iu',
            // Cadenas de 13 a 19 digitos, con o sin separadores: posible PAN.
            '/\b(?:\d[ -]?){13,19}\b/',
        ];

        return (string) preg_replace($patterns, self::REDACTED, $value);
    }
}
