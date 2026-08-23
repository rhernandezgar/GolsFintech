<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla customers. Detalle de infraestructura: el dominio
 * no lo conoce ni lo importa (regla de dependencia hexagonal).
 */
final class CustomerRecord extends Model
{
    protected $table = 'customers';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'consent_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }
}
