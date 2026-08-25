<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Port\TransactionManager;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador del puerto de transaccion sobre la conexion de Eloquent.
 *
 * `DB::transaction` anida por contador de savepoints, asi que llamar a este
 * puerto dentro de otro bloque ya abierto no abre una transaccion nueva ni
 * confirma antes de tiempo: la mas externa sigue mandando. Importa porque
 * `EloquentAuditLogger::append` ya abre la suya para encadenar el hash, y esa
 * llamada ocurre dentro del bloque que abre el caso de uso.
 */
final class EloquentTransactionManager implements TransactionManager
{
    public function transactional(callable $work): mixed
    {
        return DB::transaction($work);
    }
}
