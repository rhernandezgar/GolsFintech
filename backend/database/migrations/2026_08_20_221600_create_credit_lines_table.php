<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('credit_simulation_id')->constrained('credit_simulations')->cascadeOnUpdate()->restrictOnDelete();

            $table->decimal('authorized_amount', 15, 2);
            $table->decimal('available_balance', 15, 2);
            $table->char('currency', 3)->default('MXN');
            $table->decimal('annual_rate', 6, 4);
            $table->unsignedSmallInteger('term_months');

            $table->enum('line_status', ['active', 'blocked', 'cancelled', 'settled'])->default('active');
            $table->timestamp('opened_at');

            $table->timestamps();

            $table->index(['customer_id', 'line_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_lines');
    }
};
