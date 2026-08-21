<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/** Estado del procesamiento OCR del documento (identity_documents.ocr_status). */
enum OcrStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
