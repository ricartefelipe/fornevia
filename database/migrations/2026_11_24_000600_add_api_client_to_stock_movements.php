<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autor dos movimentos de estoque feitos pela API (ADR-031): o cliente da
 * API, como em audit_logs.actor_api_client_id. Sem chave estrangeira, para o
 * histórico sobreviver à remoção do cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->uuid('actor_api_client_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn('actor_api_client_id');
        });
    }
};
