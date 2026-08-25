<?php

declare(strict_types=1);

namespace App\Domain\Port;

/**
 * Ejecuta un bloque de escrituras como una sola unidad: o todas, o ninguna.
 *
 * ### Por que existe este puerto
 *
 * Hasta VUL-15 las transacciones vivian dentro de los adaptadores, y para una
 * escritura que toca UNA agregacion eso basta —`EloquentCustomerRegistry::register`
 * abre cliente y linea en una transaccion porque las dos son suyas—.
 *
 * El problema aparece cuando la unidad atomica cruza varios puertos. La carga
 * de una identificacion escribe en `DocumentRepository`, en `ProspectRepository`
 * y en `AuditLogger`: tres puertos, tres adaptadores. Ninguno puede abrir una
 * transaccion que cubra a los otros dos sin conocerlos, y meter el `AuditLogger`
 * dentro del repositorio romperia el patron que el proyecto sigue —el caso de
 * uso orquesta: guarda en el repositorio, anota en la bitacora—.
 *
 * La alternativa era que el caso de uso llamara a `DB::transaction`, y eso ata
 * la capa de aplicacion a Laravel. Un puerto lo resuelve sin romper la regla de
 * dependencia: la interfaz vive en el dominio y el adaptador sabe de la base de
 * datos.
 *
 * ### Que garantiza y que no
 *
 * Garantiza que un fallo dentro del bloque deshace las escrituras hechas
 * **en la base de datos**. NO deshace efectos fuera de ella: un archivo ya
 * escrito en disco sigue ahi, y una llamada a un tercero ya hecha sigue hecha.
 * Por eso el trabajo que sale del sistema —encolar la extraccion, llamar al
 * emisor de tarjetas— se deja FUERA del bloque a proposito: sostener una
 * transaccion abierta durante la latencia de una red es peor que el problema
 * que resuelve.
 */
interface TransactionManager
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function transactional(callable $work): mixed;
}
