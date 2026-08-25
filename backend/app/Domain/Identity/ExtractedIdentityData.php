<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Proyeccion PARA PANTALLA de lo que el OCR extrajo del documento (P3, P2).
 *
 * ### Por que existe
 *
 * El prototipo de la Fase 2 muestra en P3 los datos detectados con su estado de
 * legibilidad, y la Fase 3 exige que se presenten **siempre** para confirmacion
 * humana, porque el OCR puede errar. Sin esta proyeccion el prospecto confirma
 * a ciegas: acepta como suyos unos datos que no ha podido leer, y una
 * extraccion equivocada entra al expediente sin que nadie la mire. Confirmar lo
 * que no se puede revisar no es confirmar.
 *
 * ### El enmascarado es distinto del de la bitacora, y a proposito
 *
 * `SensitiveDataMasker` sustituye el dato entero por `[REDACTED]`, que es lo
 * correcto en un registro que nadie tiene por que leer. Aqui haria la pantalla
 * inutil: el prospecto no puede reconocer `[REDACTED]` como su CURP ni detectar
 * que el OCR ley un caracter de mas. Lo que se necesita es un enmascarado
 * **parcial**: bastante para reconocer, insuficiente para reconstruir.
 *
 * Se conservan los cuatro primeros caracteres y los dos ultimos, igual que
 * `Curp::masked()` y `Rfc::masked()`, de modo que la respuesta **nunca
 * transporta la CURP ni el RFC completos** (RS-03, regla de seguridad 1) y aun
 * asi el prospecto ve los suficientes para decir "esa es la mia" o "ahi hay un
 * error". El numero de documento conserva solo los cuatro ultimos.
 *
 * Cada campo viaja con `masked`, para que la interfaz pueda decir que esta
 * mostrando una parte y no el dato completo; sin esa marca, un prospecto podria
 * creer que su CURP se guardo con asteriscos.
 *
 * ### Que NO hace
 *
 * No decide si el dato es correcto ni corrige nada: solo proyecta. La
 * confianza que reporta el proveedor se traduce a tres niveles de legibilidad
 * porque un numero con dos decimales no le dice nada a quien esta leyendo la
 * pantalla; el numero crudo viaja igualmente para quien lo necesite.
 */
final readonly class ExtractedIdentityData
{
    /** Umbrales de legibilidad. Debajo del bajo, se pide revisar con atencion. */
    private const HIGH_CONFIDENCE = 0.90;

    private const MEDIUM_CONFIDENCE = 0.70;

    /**
     * Campos que NUNCA viajan completos, ni siquiera al propio titular. La
     * comparacion es por subcadena para que `curp_field` o `rfc_capturado`
     * queden cubiertos igual que `curp` y `rfc`.
     */
    private const PARTIALLY_MASKED_KEYS = ['curp', 'rfc', 'card_number', 'pan', 'cvv'];

    /** Identificadores de documento: se conservan solo los ultimos digitos. */
    private const TAIL_ONLY_KEYS = ['document_number', 'folio'];

    /** @param array<string, array{value: string, masked: bool}> $fields */
    private function __construct(
        public array $fields,
        public ?float $confidence,
        public string $legibility,
    ) {}

    /**
     * Construye la proyeccion desde el `ocr_result` tal como lo guardo el
     * worker. Devuelve null cuando no hay nada que mostrar: sin extraccion no
     * hay pantalla de revision, y un bloque vacio en la respuesta solo
     * confundiria a la interfaz.
     *
     * @param  array<string, mixed>|null  $ocrResult
     */
    public static function fromOcrResult(?array $ocrResult): ?self
    {
        if ($ocrResult === null) {
            return null;
        }

        $rawFields = $ocrResult['fields'] ?? null;

        if (! is_array($rawFields) || $rawFields === []) {
            return null;
        }

        $confidence = isset($ocrResult['confidence']) && is_numeric($ocrResult['confidence'])
            ? (float) $ocrResult['confidence']
            : null;

        $fields = [];

        foreach ($rawFields as $key => $value) {
            // Solo escalares. Una estructura anidada del proveedor no se
            // proyecta a ciegas: no se sabe que lleva dentro, y volcarla seria
            // exactamente la via por la que un dato sensible se escapa sin que
            // nadie lo haya decidido.
            if (! is_scalar($value)) {
                continue;
            }

            $name = (string) $key;
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            $fields[$name] = self::projectField($name, $text);
        }

        if ($fields === []) {
            return null;
        }

        return new self($fields, $confidence, self::legibilityFor($confidence));
    }

    /** @return array{value: string, masked: bool} */
    private static function projectField(string $name, string $value): array
    {
        $normalized = strtolower($name);

        foreach (self::PARTIALLY_MASKED_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return ['value' => self::maskKeepingEnds($value), 'masked' => true];
            }
        }

        foreach (self::TAIL_ONLY_KEYS as $identifier) {
            if (str_contains($normalized, $identifier)) {
                return ['value' => self::maskKeepingTail($value), 'masked' => true];
            }
        }

        return ['value' => $value, 'masked' => false];
    }

    /**
     * Cuatro primeros, dos ultimos, el resto en asteriscos. Es la misma forma
     * que `Curp::masked()`, para que el prospecto vea lo mismo en todas las
     * pantallas. Un valor demasiado corto para partirlo se oculta entero: mas
     * vale no mostrarlo que mostrarlo casi completo.
     */
    private static function maskKeepingEnds(string $value): string
    {
        $length = strlen($value);

        if ($length <= 6) {
            return str_repeat('*', max($length, 1));
        }

        return substr($value, 0, 4).str_repeat('*', $length - 6).substr($value, -2);
    }

    private static function maskKeepingTail(string $value): string
    {
        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', max($length, 1));
        }

        return str_repeat('*', $length - 4).substr($value, -4);
    }

    private static function legibilityFor(?float $confidence): string
    {
        if ($confidence === null) {
            return 'unknown';
        }

        if ($confidence >= self::HIGH_CONFIDENCE) {
            return 'high';
        }

        return $confidence >= self::MEDIUM_CONFIDENCE ? 'medium' : 'low';
    }

    /**
     * True cuando la legibilidad no da garantias suficientes y conviene que la
     * interfaz insista en la revision. La decision vive aqui y no en la vista:
     * el navegador no fija umbrales de negocio (RS-04).
     */
    public function needsCarefulReview(): bool
    {
        return $this->legibility !== 'high';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'confidence' => $this->confidence,
            'legibility' => $this->legibility,
            'needs_careful_review' => $this->needsCarefulReview(),
        ];
    }
}
