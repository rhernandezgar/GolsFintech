<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_validations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('identity_document_id')->nullable()->constrained('identity_documents')->cascadeOnUpdate()->restrictOnDelete();

            // Folio que permite dar seguimiento a la solicitud sin exponer datos
            // personales en canales de soporte (P4).
            $table->string('verification_folio', 32)->unique();

            $table->enum('ine_status', ['pending', 'verified', 'not_verified', 'unavailable'])->default('pending');
            $table->enum('renapo_status', ['pending', 'verified', 'not_verified', 'unavailable'])->default('pending');
            $table->enum('data_match_status', ['pending', 'verified', 'not_verified'])->default('pending');
            $table->enum('document_validity_status', ['pending', 'verified', 'not_verified'])->default('pending');
            $table->enum('fraud_evaluation_status', ['pending', 'passed', 'flagged'])->default('pending');
            $table->enum('overall_status', ['pending', 'verified', 'rejected'])->default('pending');

            // Detalle tecnico de la respuesta del proveedor, ya enmascarado: nunca
            // contiene CURP ni RFC en claro (regla de seguridad no negociable 1).
            $table->json('provider_response')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('validated_at')->nullable();

            $table->timestamps();

            $table->index(['prospect_id', 'overall_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_validations');
    }
};
