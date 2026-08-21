<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Registro Eloquent de la tabla audit_logs.
 *
 * La bitacora es APPEND-ONLY (CLAUDE.md seccion 5): aqui se bloquean UPDATE y
 * DELETE en el propio modelo, ademas de no existir ninguna ruta que los invoque.
 * Es defensa en profundidad: si alguien escribe el codigo para modificar un evento,
 * falla de inmediato y no en una revision de auditoria seis meses despues.
 *
 * Sin timestamps de Eloquent: la unica marca temporal es event_at, que forma parte
 * del material del hash.
 */
final class AuditLogRecord extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'event_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('La bitacora de auditoria es append-only: no admite modificaciones.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('La bitacora de auditoria es append-only: no admite borrados.');
        });
    }
}
