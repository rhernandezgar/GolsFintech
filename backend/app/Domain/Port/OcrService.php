<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Identity\IdentityDocument;

/**
 * Puerto del motor OCR. La extraccion es asincrona: el backend encola y el worker
 * de Node consume (Fase 2, riesgo R-03). Devuelve el identificador del trabajo para
 * poder correlacionar despues el resultado con el documento.
 */
interface OcrService
{
    public function enqueueExtraction(IdentityDocument $document): string;
}
