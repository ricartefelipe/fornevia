<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency\Scenarios;

use App\Actions\Inventory\AdjustStock;
use App\Domain\Shared\Quantity;
use App\Models\Catalog\Sku;
use App\Models\Identity\Membership;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Tests\Support\Concurrency\WorkerGate;

/**
 * Cenários de estoque: o worker age como a requisição da tela de
 * movimentação de um membro da equipe (contexto de equipe validado) e pode
 * segurar a transação aberta, com o lock do saldo, antes do commit ("hold").
 */
final class InventoryScenarios
{
    /**
     * @return array<string, Closure(array<string, mixed>, WorkerGate): array<string, mixed>>
     */
    public static function all(): array
    {
        return [

            // Ajuste pela Action: decisão sob SELECT ... FOR UPDATE do saldo.
            'adjust-stock' => static function (array $args, WorkerGate $gate): array {
                $context = ExecutionContext::staff((string) $args['actor_user_id'], (string) $args['tenant_id'], (string) $args['actor_membership_id']);

                return app(TenantDatabase::class)->run($context, static function () use ($args, $gate): array {
                    app(Access::class)->bindMembership(Membership::query()->with('role')->findOrFail((string) $args['actor_membership_id']));
                    $warehouse = Warehouse::query()->findOrFail((string) $args['warehouse_id']);
                    $sku = Sku::query()->findOrFail((string) $args['sku_id']);

                    $gate->pass($args['gate'] ?? null);
                    $balance = app(AdjustStock::class)->handle($warehouse, $sku, Quantity::of((string) $args['delta']), (string) $args['reason']);
                    $gate->pass($args['hold'] ?? null);

                    return ['on_hand' => $balance->onHand->toDatabase(), 'reserved' => $balance->reserved->toDatabase()];
                });
            },

            // Ajuste direto no ledger, sem a verificação da Action: demonstra que o
            // banco (CHECK de stock_levels) também recusa o saldo abaixo do reservado.
            'adjust-stock-ledger' => static function (array $args, WorkerGate $gate): array {
                $context = ExecutionContext::staff((string) $args['actor_user_id'], (string) $args['tenant_id'], (string) $args['actor_membership_id']);

                return app(TenantDatabase::class)->run($context, static function () use ($args, $gate): array {
                    $gate->pass($args['gate'] ?? null);
                    app(StockLedger::class)->adjust((string) $args['warehouse_id'], (string) $args['sku_id'], Quantity::of((string) $args['delta']), (string) $args['reason']);
                    $gate->pass($args['hold'] ?? null);

                    return ['adjusted' => true];
                });
            },

        ];
    }
}
