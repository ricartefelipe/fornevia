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

    // Convite de equipe emitido pela associação informada (tela de equipe).
    'invite-staff-member' => static function (array $args, WorkerGate $gate): array {
        $context = ExecutionContext::staff($args['actor_user_id'], $args['tenant_id'], $args['actor_membership_id']);

        return app(TenantDatabase::class)->run($context, static function () use ($args, $gate): array {
            app(Access::class)->bindMembership(Membership::query()->with('role')->findOrFail($args['actor_membership_id']));

            $gate->pass($args['gate'] ?? null);
            $issued = app(App\Actions\Identity\InviteMember::class)->handle(new App\Data\Identity\InviteMemberData(
                email: (string) $args['email'],
                kind: App\Enums\Identity\MembershipKind::Staff,
                roleId: (string) $args['role_id'],
            ));
            $gate->pass($args['hold'] ?? null);

            return ['invitation_id' => $issued->invitation->id];
        });
    },

    // Aceite como na rota POST /convites: transação da requisição em
    // contexto de usuário, elevada pelo AcceptInvitation ao tenant da URL.
    'accept-invitation' => static function (array $args, WorkerGate $gate): array {
        $user = App\Models\Identity\User::query()->findOrFail($args['user_id']);

        return app(TenantDatabase::class)->run(ExecutionContext::user($user->id), static function () use ($args, $gate, $user): array {
            $gate->pass($args['gate'] ?? null);
            $membership = app(App\Actions\Identity\AcceptInvitation::class)->handle((string) $args['tenant_id'], (string) $args['token'], $user);
            $gate->pass($args['hold'] ?? null);

            return ['membership_id' => $membership->id];
        });
    },

] + (require __DIR__.'/ordering-scenarios.php') + (require __DIR__.'/credit-scenarios.php') + (require __DIR__.'/inventory-scenarios.php');
