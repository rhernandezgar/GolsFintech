<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('credit_line_id')->constrained('credit_lines')->cascadeOnUpdate()->restrictOnDelete();

            // El PAN completo NUNCA se almacena: solo el token emitido por el
            // procesador y los ultimos cuatro digitos para desplegar (PCI DSS, RS-03).
            $table->string('tokenized_card_number', 64)->unique();
            $table->char('last_four', 4);
            $table->enum('brand', ['visa', 'mastercard', 'amex', 'other'])->default('visa');

            $table->unsignedTinyInteger('expiration_month');
            $table->unsignedSmallInteger('expiration_year');

            $table->enum('card_status', ['issued', 'active', 'blocked', 'cancelled', 'expired'])->default('issued');
            $table->timestamp('issued_at');

            $table->timestamps();

            $table->index(['customer_id', 'card_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
