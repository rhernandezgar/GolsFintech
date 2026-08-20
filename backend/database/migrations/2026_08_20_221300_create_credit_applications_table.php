<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('identity_validation_id')->nullable()->constrained('identity_validations')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('application_folio', 32)->unique();

            // Tipo de credito resuelto por el motor de reglas del dominio (RF-05).
            $table->enum('credit_type', ['personal', 'business', 'microcredit'])->nullable();
            $table->enum('application_status', [
                'draft',
                'under_review',
                'pre_approved',
                'approved',
                'rejected',
                'expired',
            ])->default('draft');

            // Ingreso validado y capacidad de pago estimada por el motor (RF-06).
            $table->decimal('validated_monthly_income', 15, 2)->nullable();
            $table->decimal('payment_capacity', 15, 2)->nullable();

            // Motivo interno del rechazo. Al prospecto se le muestra un mensaje
            // generico: el detalle no viaja al cliente (riesgo R-01).
            $table->string('rejection_reason_code', 40)->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['prospect_id', 'application_status']);
            $table->index('application_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_applications');
    }
};
