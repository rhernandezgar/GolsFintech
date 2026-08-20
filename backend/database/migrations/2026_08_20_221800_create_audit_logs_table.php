<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitacora de auditoria append-only (RF-13, RS-06, RNF-07).
     *
     * Usa referencia polimorfica en lugar de una llave foranea por entidad:
     * prospect_id es el ancla que persiste durante todo el ciclo de vida —incluso
     * despues de que el prospecto se convierte en cliente— y affected_entity /
     * affected_entity_id precisan sobre que registro concreto ocurrio el evento.
     * previous_hash y current_hash forman la cadena SHA-256 que hace detectable
     * cualquier alteracion o borrado (amenaza de repudio del modelado STRIDE).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable porque hay eventos previos a la existencia del prospecto
            // (por ejemplo, autenticacion de un usuario administrativo).
            $table->foreignId('prospect_id')->nullable()->constrained('prospects')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('affected_entity', 60);
            $table->unsignedBigInteger('affected_entity_id')->nullable();

            $table->string('event_type', 60);
            $table->string('actor', 120);
            $table->string('ip_address', 45)->nullable();

            // Contexto del evento ya enmascarado: nunca CURP, RFC ni PAN en claro
            // (regla de seguridad no negociable 1).
            $table->json('metadata')->nullable();

            $table->timestamp('event_at');

            $table->char('previous_hash', 64)->nullable();
            $table->char('current_hash', 64)->unique();

            // Sin timestamps de Eloquent: el registro es inmutable y su unica marca
            // temporal es event_at, que forma parte del material del hash.

            $table->index(['prospect_id', 'event_at']);
            $table->index(['affected_entity', 'affected_entity_id']);
            $table->index('event_type');
            $table->index('event_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
