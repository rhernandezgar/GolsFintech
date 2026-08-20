<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('prospect_id')->unique()->constrained('prospects')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('credit_application_id')->constrained('credit_applications')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('customer_number', 20)->unique();
            $table->string('full_name');
            $table->string('contract_folio', 32)->unique();

            $table->enum('customer_status', ['active', 'suspended', 'closed'])->default('active');

            // Consentimiento con sello de tiempo y version del contrato aceptada:
            // soporte probatorio frente a un eventual repudio (P6).
            $table->string('contract_version', 20);
            $table->timestamp('consent_at');
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();

            $table->index('customer_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
