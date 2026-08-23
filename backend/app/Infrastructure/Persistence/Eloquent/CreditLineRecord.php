<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla credit_lines. Detalle de infraestructura: el dominio
 * no lo conoce ni lo importa (regla de dependencia hexagonal).
 */
final class CreditLineRecord extends Model
{
    protected $table = 'credit_lines';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'authorized_amount' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'annual_rate' => 'decimal:4',
            'opened_at' => 'immutable_datetime',
        ];
    }
}
