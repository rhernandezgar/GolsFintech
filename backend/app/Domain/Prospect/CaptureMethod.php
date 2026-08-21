<?php

declare(strict_types=1);

namespace App\Domain\Prospect;

/** Ruta elegida por el prospecto en P1 (prospects.capture_method, RF-01). */
enum CaptureMethod: string
{
    case Manual = 'manual';
    case Ocr = 'ocr';
}
