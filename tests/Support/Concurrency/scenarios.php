<?php

declare(strict_types=1);

use App\Actions\Identity\RevokeMembership;
use App\Models\Identity\Membership;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Tests\Support\Concurrency\WorkerGate;

/*
 * Cenários executados pelos workers. Cada cenário estabelece o próprio
 * contexto de tenant, como uma requisição real, e pode atravessar portões:
 * "gate" antes da operação e "hold" antes do commit.
 */
return [

    'revoke-membership' => static function (array $args, WorkerGate $gate): array {
        $context = ExecutionContext::staff($args['actor_user_id'], $args['tenant_id'], $args['actor_membership_id']);

        return app(TenantDatabase::class)->run($context, static function () use ($args, $gate): array {
            app(Access::class)->bindMembership(Membership::query()->with('role')->findOrFail($args['actor_membership_id']));
            $target = Membership::query()->findOrFail($args['target_membership_id']);

            $gate->pass($args['gate'] ?? null);
            app(RevokeMembership::class)->handle($target, (string) $args['reason']);
            $gate->pass($args['hold'] ?? null);

            return ['revoked' => $target->id];
        });
    },

];
