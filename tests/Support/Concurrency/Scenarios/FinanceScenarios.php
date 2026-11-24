<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency\Scenarios;

use App\Actions\Finance\Staff\RefundPayment;
use App\Models\Identity\Membership;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Tests\Support\Concurrency\WorkerGate;

/**
 * Cenários do financeiro: o worker age como a requisição da equipe do
 * financeiro e pode segurar a transação aberta antes do commit ("hold").
 */
final class FinanceScenarios
{
    /**
     * @return array<string, Closure(array<string, mixed>, WorkerGate): array<string, mixed>>
     */
    public static function all(): array
    {
        return [

            'refund-payment' => static function (array $args, WorkerGate $gate): array {
                $membership = app(TenantDatabase::class)->run(
                    ExecutionContext::staff($args['user_id'], $args['tenant_id'], $args['membership_id']),
                    static fn () => Membership::query()->with(['role', 'tenant'])->findOrFail($args['membership_id']),
                );

                return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
                    app(Access::class)->bindMembership($membership);

                    $refund = app(RefundPayment::class)->handle((string) $args['payment_id'], (string) $args['amount'], 'Reembolso concorrente');

                    $gate->pass($args['hold'] ?? null);

                    return ['refund_id' => $refund->id];
                });
            },

        ];
    }
}
