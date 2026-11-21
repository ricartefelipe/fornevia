<?php

declare(strict_types=1);

use App\Actions\Approvals\DecideOrderApproval;
use App\Enums\Approvals\ApprovalDecision;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Tests\Support\Concurrency\WorkerGate;

/*
 * Cenários de aprovação: o worker age como a requisição do aprovador
 * (contexto de comprador validado) e pode segurar a transação aberta antes
 * do commit ("hold").
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

            $outcome = app(DecideOrderApproval::class)->handle(Order::query()->findOrFail($args['order_id']), ApprovalDecision::Approved);

            $gate->pass($args['hold'] ?? null);

            return ['outcome' => $outcome->value];
        });
    },

];
