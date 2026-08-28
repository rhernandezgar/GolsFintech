<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Una CURP, una solicitud ACTIVA a la vez», impuesto por la base (VUL-17).
 *
 * ### Por que no basta con la politica de aplicacion
 *
 * `ReapplicationPolicy` decide correctamente, pero el UNIQUE que habia sobre
 * `curp_hash` **era** la regla vieja escrita en el esquema: aunque la politica
 * autorizara la nueva solicitud tras caducar la anterior, el INSERT chocaba con
 * el indice y el endpoint respondia 500. La regla estaba en dos sitios y los dos
 * decian cosas distintas.
 *
 * ### Por que una columna generada y no una mantenida por la aplicacion
 *
 * MySQL no tiene indices parciales, asi que el patron para «unico solo entre las
 * filas activas» es un indice unico sobre una columna que vale NULL en las
 * inactivas: MySQL considera cada NULL distinto de los demas, de modo que
 * conviven todas las abandonadas que haga falta y a lo sumo una viva.
 *
 * Se declara **GENERATED ALWAYS ... STORED** y no como columna normal que
 * actualice el codigo. La diferencia importa: una columna mantenida a mano
 * depende de que todos los caminos que cambian `capture_status` se acuerden de
 * actualizarla, y el dia que uno lo olvide la restriccion se relaja **en
 * silencio** —sin error, sin prueba en rojo, sin nada que lo delate—. Generada,
 * la base la deriva sola y no hay forma de desincronizarla.
 *
 * `curp_hash` conserva un indice NO unico: se sigue buscando por el, que es
 * para lo que existe (CLAUDE.md §6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function ($table): void {
            $table->dropUnique('prospects_curp_hash_unique');
        });

        // Se recupera como indice de busqueda: `findByCurp` y la instantanea de
        // reintento lo consultan en cada captura.
        Schema::table('prospects', function ($table): void {
            $table->index('curp_hash', 'prospects_curp_hash_index');
        });

        DB::statement(
            "ALTER TABLE prospects
             ADD COLUMN active_curp_hash CHAR(64)
             GENERATED ALWAYS AS (IF(capture_status = 'abandoned', NULL, curp_hash)) STORED"
        );

        DB::statement(
            'ALTER TABLE prospects
             ADD CONSTRAINT prospects_active_curp_hash_unique UNIQUE (active_curp_hash)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prospects DROP INDEX prospects_active_curp_hash_unique');
        DB::statement('ALTER TABLE prospects DROP COLUMN active_curp_hash');

        Schema::table('prospects', function ($table): void {
            $table->dropIndex('prospects_curp_hash_index');
            $table->unique('curp_hash', 'prospects_curp_hash_unique');
        });
    }
};
