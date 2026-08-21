<?php

declare(strict_types=1);

namespace App\Domain\Prospect;

/** Avance del prospecto por el embudo de captura (prospects.capture_status). */
enum CaptureStatus: string
{
    case Started = 'started';
    case DataCaptured = 'data_captured';
    case DocumentUploaded = 'document_uploaded';
    case DataConfirmed = 'data_confirmed';
    case Abandoned = 'abandoned';
}
