<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro Eloquent de la tabla credit_applications. Detalle de infraestructura:
 * el dominio no lo conoce ni lo importa (regla de dependencia hexagonal).
 *
 * La ruta lo enlaza por public_id y no por el id autoincremental: un
 * identificador secuencial invita a recorrer las solicitudes ajenas cambiando
 * un numero, y aunque la autorizacion a nivel de objeto lo impida, no hay razon
 * para publicar cuantas solicitudes existen.
 */
final class CreditApplicationRecord extends Model
{
    protected $table = 'credit_applications';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(ProspectRecord::class, 'prospect_id');
    }

    protected function casts(): array
    {
        return [
            'validated_monthly_income' => 'decimal:2',
            'payment_capacity' => 'decimal:2',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
