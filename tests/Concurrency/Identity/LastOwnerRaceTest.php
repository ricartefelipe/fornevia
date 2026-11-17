<?php

declare(strict_types=1);

use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use Tests\Support\Builders\TenantScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Dois proprietários revogam um ao outro ao mesmo tempo, em processos
 * separados. O OwnershipGuard bloqueia todas as associações de
 * proprietário antes de contar: exatamente uma revogação prevalece e a
 * organização nunca fica sem proprietário ativo.
 */
it('mantém ao menos um proprietário quando dois revogam um ao outro simultaneamente', function (): void {
    $tenant = TenantScenario::create();
    $first = $tenant->ownerMembership;
    $second = $tenant->addStaff(SystemRole::Owner);

    $harness = new ConcurrencyHarness;
    $gate = $harness->gate('owners-race');

    try {
        $a = $harness->start('revoke-membership', [
            'tenant_id' => $tenant->organization->id,
            'actor_user_id' => $first->user_id,
            'actor_membership_id' => $first->id,
            'target_membership_id' => $second->id,
            'reason' => 'Saída da sociedade',
            'gate' => 'owners-race',
        ], 'owner-a');
        $b = $harness->start('revoke-membership', [
            'tenant_id' => $tenant->organization->id,
            'actor_user_id' => $second->user_id,
            'actor_membership_id' => $second->id,
            'target_membership_id' => $first->id,
            'reason' => 'Saída da sociedade',
            'gate' => 'owners-race',
        ], 'owner-b');

        $harness->waitUntilWaitingAt('owners-race', 2);
        $gate->release();

        $results = [$a->result(), $b->result()];
    } finally {
        $harness->shutdown();
    }

    $succeeded = array_values(array_filter($results, static fn (array $r): bool => $r['ok']));
    $failed = array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']));

    expect($succeeded)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['error'])->toBe(App\Domain\Identity\Exceptions\LastOwnerRequired::class);

    $activeOwners = $tenant->system(fn (): int => Membership::query()
        ->whereIn('id', [$first->id, $second->id])
        ->where('status', MembershipStatus::Active->value)
        ->count());

    expect($activeOwners)->toBe(1);
});
