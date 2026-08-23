<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla credit_simulations. Detalle de infraestructura: el dominio
 * no lo conoce ni lo importa (regla de dependencia hexagonal).
 */
final class CreditSimulationRecord extends Model
{
    protected $table = 'credit_simulations';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'proposed_amount' => 'decimal:2',
            'annual_rate' => 'decimal:4',
            'cat' => 'decimal:4',
            'estimated_monthly_payment' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
