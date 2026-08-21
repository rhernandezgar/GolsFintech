<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** Registro Eloquent de la tabla identity_documents. */
final class IdentityDocumentRecord extends Model
{
    protected $table = 'identity_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ocr_result' => 'array',
            'file_size_bytes' => 'integer',
            'ocr_attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
