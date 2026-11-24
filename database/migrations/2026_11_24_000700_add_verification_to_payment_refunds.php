<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conferência dos reembolsos em verificação (ADR-028): quando entraram em
 * verificação, se o pedido ao gateway levou a referência do reembolso
 * (sem ela, só a conclusão manual) e a última conferência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->timestampTz('verification_started_at')->nullable();
            $table->boolean('gateway_reference_sent')->default(false);
            $table->timestampTz('last_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropColumn(['verification_started_at', 'gateway_reference_sent', 'last_checked_at']);
        });
    }
};
