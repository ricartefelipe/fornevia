<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\DB;

/**
 * Versão de preços do tenant. Toda Action que altera algo que influencia o
 * preço resolvido (tabelas, itens, faixas, condições negociadas, grupo da
 * empresa) chama bump() na mesma transação; o cache passa a usar a nova
 * versão e entradas antigas deixam de ser lidas.
 */
final readonly class PriceVersion
{
    public function __construct(private CurrentContext $context) {}

    public function current(): int
    {
        $tenantId = $this->context->tenantId();
        $version = DB::table('pricing_settings')->where('tenant_id', $tenantId)->value('price_version');

        return is_numeric($version) ? (int) $version : 1;
    }

    public function bump(): int
    {
        $row = DB::selectOne(
            'insert into pricing_settings (tenant_id, price_version, updated_at) values (?, 2, now())
             on conflict (tenant_id) do update set price_version = pricing_settings.price_version + 1, updated_at = now()
             returning price_version',
            [$this->context->tenantId()],
        );

        return (int) $row->price_version;
    }
}
