<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla identity_validations. Detalle de infraestructura: el dominio
 * no lo conoce ni lo importa (regla de dependencia hexagonal).
 */
final class IdentityValidationRecord extends Model
{
    protected $table = 'identity_validations';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'validated_at' => 'immutable_datetime',
        ];
    }
}
