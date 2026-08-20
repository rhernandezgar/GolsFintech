<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnUpdate()->restrictOnDelete();

            $table->enum('document_type', ['INE', 'passport', 'other']);

            // El archivo vive cifrado fuera de la raiz web; aqui solo su referencia.
            $table->string('storage_path');
            $table->string('original_extension', 10)->nullable();
            $table->string('detected_mime_type', 100);
            $table->unsignedInteger('file_size_bytes');

            // Hash SHA-256 calculado al cargar y verificado antes de cada procesamiento,
            // para garantizar que la imagen enviada al OCR es la que subio el prospecto.
            $table->char('file_hash', 64);

            $table->enum('ocr_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->json('ocr_result')->nullable();
            $table->unsignedTinyInteger('ocr_attempts')->default(0);
            $table->string('ocr_job_id')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['prospect_id', 'ocr_status']);
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_documents');
    }
};
