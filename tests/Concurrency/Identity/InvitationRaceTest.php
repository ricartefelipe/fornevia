<?php

declare(strict_types=1);

use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\Notification;
use Tests\Integration\Identity\Invitations\InvitationFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Convites disputando a última vaga do plano e aceites simultâneos do
 * mesmo convite, em processos separados. O primeiro worker para antes do
 * commit (portão "hold"); o segundo só é liberado depois de confirmado
 * (pg_stat_activity) que está bloqueado no lock da primeira transação.
 */

it('emite exatamente um convite quando dois disputam a última vaga da equipe no plano', function (): void {
    $tenant = TenantScenario::create('essencial');
    $tenant->addStaff(SystemRole::SalesRep);
    $tenant->addStaff(SystemRole::Warehouse);
    $tenant->addStaff(SystemRole::Finance);
    $roleId = $tenant->role(SystemRole::Viewer)->id;

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('invite-hold');

    $args = static fn (string $email, ?string $holdGate): array => [
        'tenant_id' => $tenant->organization->id,
        'actor_user_id' => $tenant->owner->id,
        'actor_membership_id' => $tenant->ownerMembership->id,
        'email' => $email,
        'role_id' => $roleId,
        'hold' => $holdGate,
    ];

    try {
        // 4 usuários ativos no plano Essencial (limite 5): resta uma vaga.
        $first = $harness->start('invite-staff-member', $args('primeira.vaga@example.com', 'invite-hold'), 'invite-a');
        $harness->waitUntilWaitingAt('invite-hold', 1);

        $second = $harness->start('invite-staff-member', $args('segunda.vaga@example.com', null), 'invite-b');
        $harness->waitUntilBlockedOnLock('invite-b');
        $hold->release();

        $results = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    expect($results[0]['ok'])->toBeTrue()
        ->and($results[1]['ok'])->toBeFalse()
        ->and($results[1]['error'])->toBe(PlanLimitReached::class);

    $pending = $tenant->system(fn (): array => Invitation::query()
        ->where('kind', MembershipKind::Staff->value)
        ->whereNull('accepted_at')
        ->whereNull('revoked_at')
        ->pluck('email')
        ->all());

    expect($pending)->toBe(['primeira.vaga@example.com']);
});

it('mantém uma única associação ativa quando o mesmo convite é aceito duas vezes ao mesmo tempo', function (): void {
    Notification::fake();
    $tenant = TenantScenario::create();
    $issued = Fixtures::issue($tenant, $tenant->ownerMembership, 'claudia.mota@example.com', SystemRole::SalesRep);
    $invitee = User::factory()->unverified()->create(['email' => 'claudia.mota@example.com']);

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('accept-hold');

    $args = static fn (?string $holdGate): array => [
        'tenant_id' => $tenant->organization->id,
        'user_id' => $invitee->id,
        'token' => $issued->plainToken,
        'hold' => $holdGate,
    ];

    try {
        $first = $harness->start('accept-invitation', $args('accept-hold'), 'accept-a');
        $harness->waitUntilWaitingAt('accept-hold', 1);

        $second = $harness->start('accept-invitation', $args(null), 'accept-b');
        $harness->waitUntilBlockedOnLock('accept-b');
        $hold->release();

        $results = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    expect($results[0]['ok'])->toBeTrue(json_encode($results[0], JSON_THROW_ON_ERROR))
        ->and($results[1]['ok'])->toBeTrue(json_encode($results[1], JSON_THROW_ON_ERROR))
        ->and($results[1]['data']['membership_id'])->toBe($results[0]['data']['membership_id']);

    $memberships = $tenant->system(fn (): array => Membership::query()->where('user_id', $invitee->id)->get()->all());

    expect($memberships)->toHaveCount(1)
        ->and($memberships[0]->id)->toBe($results[0]['data']['membership_id'])
        ->and($memberships[0]->status)->toBe(MembershipStatus::Active)
        ->and(Fixtures::audits($tenant, 'membership.granted'))->toHaveCount(1)
        ->and(Fixtures::invitation($tenant, $issued->invitation->id)->accepted_by_user_id)->toBe($invitee->id);
});

it('trata o envio duplicado e simultâneo do mesmo convite sem erro de banco', function (): void {
    $tenant = TenantScenario::create();
    $roleId = $tenant->role(SystemRole::Viewer)->id;

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('resend-hold');

    $args = static fn (?string $holdGate): array => [
        'tenant_id' => $tenant->organization->id,
        'actor_user_id' => $tenant->owner->id,
        'actor_membership_id' => $tenant->ownerMembership->id,
        'email' => 'clique.duplo@example.com',
        'role_id' => $roleId,
        'hold' => $holdGate,
    ];

    try {
        $first = $harness->start('invite-staff-member', $args('resend-hold'), 'resend-a');
        $harness->waitUntilWaitingAt('resend-hold', 1);

        $second = $harness->start('invite-staff-member', $args(null), 'resend-b');
        $harness->waitUntilBlockedOnLock('resend-b');
        $hold->release();

        $results = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    // O segundo envio pode reemitir o convite (revogando o primeiro) ou ser
    // recusado por regra de negócio, mas nunca falhar com erro de banco.
    expect($results[0]['ok'])->toBeTrue()
        ->and($results[1]['ok'] || is_subclass_of($results[1]['error'], BusinessRuleViolation::class))
        ->toBeTrue('Segundo envio: '.json_encode($results[1], JSON_THROW_ON_ERROR));

    $pending = $tenant->system(fn (): int => Invitation::query()
        ->where('email', 'clique.duplo@example.com')
        ->whereNull('accepted_at')
        ->whereNull('revoked_at')
        ->count());

    expect($pending)->toBe(1);
});
