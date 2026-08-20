<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_simulations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('credit_application_id')->constrained('credit_applications')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('simulation_folio', 32)->unique();

            $table->decimal('proposed_amount', 15, 2);
            $table->decimal('annual_rate', 6, 4);
            $table->decimal('cat', 6, 4)->nullable();
            $table->unsignedSmallInteger('term_months');
            $table->decimal('estimated_monthly_payment', 15, 2);
            $table->decimal('total_payable', 15, 2);

            $table->enum('simulation_status', ['proposed', 'accepted', 'rejected', 'expired'])->default('proposed');

            // Vigencia: impide reutilizar condiciones caducadas (P5).
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['credit_application_id', 'simulation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_simulations');
    }
};
