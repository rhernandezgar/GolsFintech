<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rol, segundo factor y anclaje del usuario a su prospecto o su cliente.
 *
 * El anclaje es lo que hace posible la autorizacion a nivel de objeto que exige
 * la Fase 3 §4.9: sin una columna que diga de quien es esta cuenta, comprobar
 * que un prospecto solo consulta su propia solicitud es imposible y el control
 * degenera en "puede consultar solicitudes", que es justo el fallo descrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'prospect',
                'customer',
                'admin',
                'auditor',
                'risk_analyst',
            ])->default('prospect')->after('email');

            // Secreto TOTP cifrado a nivel de columna (cast encrypted en el
            // modelo), por eso es TEXT: el ciphertext no cabe en un varchar
            // corto. Quien obtenga una copia de la tabla no puede generar
            // codigos validos sin la llave de la aplicacion.
            $table->text('two_factor_secret')->nullable()->after('password');

            // Mientras sea NULL el alta del segundo factor esta a medias: el
            // secreto existe pero el usuario todavia no ha demostrado que su
            // autenticador lo tiene.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');

            // Momento de la ultima verificacion de segundo factor. La consulta
            // de los datos completos de la tarjeta exige reautenticacion
            // reciente (Fase 3 §4.9, pantalla P6).
            $table->timestamp('two_factor_verified_at')->nullable()->after('two_factor_confirmed_at');

            $table->foreignId('prospect_id')->nullable()->after('two_factor_verified_at')
                ->constrained('prospects')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->after('prospect_id')
                ->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['prospect_id']);
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['role']);
            $table->dropColumn([
                'role',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'two_factor_verified_at',
                'prospect_id',
                'customer_id',
            ]);
        });
    }
};
