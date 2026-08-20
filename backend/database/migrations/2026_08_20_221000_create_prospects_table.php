<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->string('full_name');

            // CURP y RFC se guardan cifrados a nivel de columna (cast encrypted en el
            // modelo), por eso son TEXT: el ciphertext no cabe en un varchar corto.
            // Los *_hash son SHA-256 deterministas y existen unicamente para buscar y
            // detectar duplicados sin descifrar ni exponer el dato en claro.
            $table->text('curp')->nullable();
            $table->char('curp_hash', 64)->nullable()->unique();
            $table->text('rfc')->nullable();
            $table->char('rfc_hash', 64)->nullable()->index();

            $table->unsignedTinyInteger('age')->nullable();
            $table->enum('sex', ['H', 'M', 'X'])->nullable();
            $table->text('address')->nullable();
            $table->string('geographic_location')->nullable();
            $table->string('business_type')->nullable();
            $table->decimal('monthly_income', 15, 2)->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();

            // Ruta elegida por el prospecto en P1: captura manual u OCR (RF-01).
            $table->enum('capture_method', ['manual', 'ocr']);
            $table->enum('capture_status', [
                'started',
                'data_captured',
                'document_uploaded',
                'data_confirmed',
                'abandoned',
            ])->default('started');

            $table->timestamp('privacy_notice_accepted_at')->nullable();
            $table->timestamps();

            $table->index('capture_status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
