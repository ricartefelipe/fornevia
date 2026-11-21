<?php

declare(strict_types=1);

use App\Actions\Approvals\DecideOrderApproval;
use App\Actions\Approvals\RevokeApprovalDelegation;
use App\Data\Approvals\ReviewedApproval;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Tests\Support\Concurrency\WorkerGate;

/*
 * Cenários de aprovação: o worker age como a requisição do aprovador ou do
 * titular de uma delegação (contexto de comprador validado) e pode segurar
 * a transação aberta antes do commit ("hold").
 */
return [

    'decide-approval' => static function (array $args, WorkerGate $gate): array {
        $membership = app(TenantDatabase::class)->run(
            ExecutionContext::buyer($args['user_id'], $args['tenant_id'], $args['buyer_company_id'], $args['membership_id']),
            static fn () => Membership::query()->with(['role', 'tenant', 'buyerCompany'])->findOrFail($args['membership_id']),
        );

        return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
            app(Access::class)->bindMembership($membership);
            $gate->pass($args['gate'] ?? null);

            // Versão que a tela do aprovador mostraria ao abrir o pedido.
            $order = Order::query()->findOrFail($args['order_id']);
            $reviewed = ReviewedApproval::of($order, OrderApprovalStep::query()
                ->where('order_id', $order->id)
                ->where('status', ApprovalStepStatus::Pending->value)
                ->orderBy('level_number')
                ->first());

            $outcome = app(DecideOrderApproval::class)->handle($order, ApprovalDecision::Approved, null, $reviewed);

            $gate->pass($args['hold'] ?? null);

            return ['outcome' => $outcome->value];
        });
    },

    'revoke-delegation' => static function (array $args, WorkerGate $gate): array {
        $membership = app(TenantDatabase::class)->run(
            ExecutionContext::buyer($args['user_id'], $args['tenant_id'], $args['buyer_company_id'], $args['membership_id']),
            static fn () => Membership::query()->with(['role', 'tenant', 'buyerCompany'])->findOrFail($args['membership_id']),
        );

        return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
            app(Access::class)->bindMembership($membership);

            $delegation = app(RevokeApprovalDelegation::class)->handle($args['delegation_id'], 'Retorno antecipado das férias');

            $gate->pass($args['hold'] ?? null);

            return ['revoked' => $delegation->revoked_at !== null];
        });
    },

];
