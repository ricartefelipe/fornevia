<?php

declare(strict_types=1);

use App\Actions\Credit\ChangeCreditLimit;
use App\Domain\Shared\Money;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Services\Credit\CreditLedger;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Tests\Support\Concurrency\WorkerGate;

/*
 * Cenários de crédito comercial: o worker age como a ação da tela da
 * equipe (contexto de equipe validado) e pode segurar a transação aberta,
 * com a conta bloqueada, antes do commit ("hold"). O resultado traz a
 * posição vista pela própria transação após a alteração.
 */
return [

    'change-credit-limit' => static function (array $args, WorkerGate $gate): array {
        $context = ExecutionContext::staff($args['actor_user_id'], $args['tenant_id'], $args['actor_membership_id']);

        return app(TenantDatabase::class)->run($context, static function () use ($args, $gate): array {
            app(Access::class)->bindMembership(Membership::query()->with('role')->findOrFail($args['actor_membership_id']));
            $company = BuyerCompany::query()->findOrFail($args['buyer_company_id']);

            $gate->pass($args['gate'] ?? null);
            $account = app(ChangeCreditLimit::class)->handle($company, Money::ofCents((int) $args['limit_cents']), (string) $args['reason']);
            $position = app(CreditLedger::class)->positionFor($company->id);
            $gate->pass($args['hold'] ?? null);

            return [
                'limit_cents' => $account->limit_cents->cents,
                'committed_cents' => $position->committed->cents,
                'exposure_cents' => $position->exposure()->cents,
                'available_cents' => $position->available()->cents,
            ];
        });
    },

];
