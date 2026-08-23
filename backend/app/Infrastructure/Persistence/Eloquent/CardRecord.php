<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla cards. Detalle de infraestructura: el dominio
 * no lo conoce ni lo importa (regla de dependencia hexagonal).
 */
final class CardRecord extends Model
{
    protected $table = 'cards';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
        ];
    }
}
