<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Port\TransactionManager;

/**
 * Doble del puerto de transaccion para las pruebas unitarias.
 *
 * Ejecuta el bloque tal cual, sin transaccion: los dobles en memoria no tienen
 * una que abrir. Se llama `Immediate` y no `Fake` porque no simula nada —hace
 * exactamente lo que promete el puerto cuando no hay base de datos detras—.
 *
 * **Lo que esto NO acredita:** que un fallo a mitad deshaga las escrituras. Con
 * dobles en memoria no hay rollback que ejercer, asi que una prueba unitaria
 * que use esto puede comprobar el ORDEN de las escrituras pero nunca su
 * atomicidad. Eso se prueba contra MySQL, en
 * `DocumentUploadTest::a_failure_while_recording_leaves_no_orphan_row`.
 * Confundir las dos cosas es como se acaba creyendo que un control esta
 * probado cuando no lo esta.
 */
final class ImmediateTransactionManager implements TransactionManager
{
    public int $calls = 0;

    public function transactional(callable $work): mixed
    {
        $this->calls++;

        return $work();
    }
}
