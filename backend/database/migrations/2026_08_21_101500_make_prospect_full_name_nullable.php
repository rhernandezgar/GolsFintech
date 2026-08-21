<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige una inconsistencia del esquema creado en T2.
 *
 * prospects.capture_status admite el estado 'started', que es el momento en que el
 * prospecto elige metodo de captura en P1 y todavia no ha escrito su nombre. Con
 * full_name declarado NOT NULL ese estado era irrepresentable: la fila no podia
 * existir hasta la pantalla siguiente, y entonces el evento prospect.started de la
 * bitacora se quedaba sin entidad a la cual apuntar.
 *
 * No se modifica la migracion de T2 —ya esta commiteada y aplicada—, se corrige con
 * una migracion nueva. No cambia el nombre ni el tipo de la columna: solo su
 * nulabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table): void {
            $table->string('full_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table): void {
            $table->string('full_name')->nullable(false)->change();
        });
    }
};
