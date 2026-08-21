<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

/**
 * Desenlace que simula el OCR para un documento.
 *
 * Viaja dentro del identificador del trabajo, de modo que el worker de Node (T7)
 * sepa que debe simular sin consultar la base ni compartir configuracion con el
 * backend: el identificador es el contrato entre los dos procesos.
 */
enum OcrScenario: string
{
    /** Extraccion correcta. */
    case Extracted = 'extracted';

    /** El proveedor no responde a tiempo: es el caso que ejercita los reintentos. */
    case Timeout = 'timeout';

    /** El proveedor responde, pero la imagen no es legible: no se reintenta. */
    case Unreadable = 'unreadable';

    /** Un timeout se reintenta; un documento ilegible no mejora por reintentarlo. */
    public function isRetryable(): bool
    {
        return $this === self::Timeout;
    }
}
