<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Papéis de sistema são gravados em cada tenant na criação da organização.
 * O administrador da empresa compradora dos tenants já existentes recebe a
 * permissão nova de regras e delegações de aprovação (portal.approval_rules),
 * que os tenants novos já recebem pela definição do papel.
 */
return new class extends Migration
{
    private const string PERMISSION = 'portal.approval_rules';

    public function up(): void
    {
        TenantSchema::forEachTenant(static function (): void {
            DB::update(
                "update roles set permissions = permissions || ?::jsonb, updated_at = now()
                  where key = 'buyer_admin' and is_system and not permissions @> ?::jsonb",
                [json_encode([self::PERMISSION], JSON_THROW_ON_ERROR), json_encode([self::PERMISSION], JSON_THROW_ON_ERROR)],
            );
        });
    }

    public function down(): void
    {
        TenantSchema::forEachTenant(static function (): void {
            DB::update(
                "update roles set permissions = permissions - ?, updated_at = now()
                  where key = 'buyer_admin' and is_system",
                [self::PERMISSION],
            );
        });
    }
};
