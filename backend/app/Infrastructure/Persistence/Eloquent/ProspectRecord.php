<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla prospects. Es un detalle de infraestructura: el
 * dominio no lo conoce ni lo importa nunca (regla de dependencia hexagonal).
 *
 * curp y rfc se guardan cifrados a nivel de columna con el cast 'encrypted', que usa
 * la llave de la aplicacion. Su busqueda va por curp_hash y rfc_hash.
 */
final class ProspectRecord extends Model
{
    protected $table = 'prospects';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'curp' => 'encrypted',
            'rfc' => 'encrypted',
            'age' => 'integer',
            'privacy_notice_accepted_at' => 'immutable_datetime',
        ];
    }
}
