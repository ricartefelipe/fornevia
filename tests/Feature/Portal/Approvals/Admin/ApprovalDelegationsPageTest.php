<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Portal\Approvals\Admin\ApprovalDelegationsIndex;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Approvals\Admin\ApprovalAdminFixtures as Admin;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Tela de delegações de aprovação (portal.delegations): o administrador da
| empresa vê e administra todas; o aprovador vê as que deu ou recebeu e
| delega as próprias aprovações. Vigentes, futuras e encerradas; criação
| com erros nos campos e revogação.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->startOfSecond());
    app()->instance(Clock::class, $this->clock);
    $this->today = $this->clock->today();

    $this->scenario = OrderingScenario::create();
    $this->company = $this->scenario->tenant->addBuyerCompany();
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin, User::factory()->create(['name' => 'Diana Administradora']));
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver, User::factory()->create(['name' => 'Ana Aprovadora']));
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver, User::factory()->create(['name' => 'Bruno Aprovador']));
    $this->carla = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver, User::factory()->create(['name' => 'Carla Aprovadora']));
    $this->purchaser = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Purchaser, User::factory()->create(['name' => 'Paulo Comprador']));

    $this->page = function (?Membership $membership = null): TestResponse {
        $membership ??= $this->admin;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get(route('portal.delegations'));
    };

    $this->as = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->admin;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };

    $this->stored = fn (): array => $this->scenario->system(static fn (): array => ApprovalDelegation::query()
        ->orderBy('created_at')
        ->get()
        ->map(static fn (ApprovalDelegation $d): array => [$d->delegator_membership_id, $d->delegate_membership_id, $d->revoked_at !== null])
        ->all());
});

it('mostra as delegações vigentes, futuras e encerradas ao administrador', function (): void {
    $now = $this->clock->now();
    Admin::delegation($this->scenario, $this->ana, $this->bruno, $now->subDay(), $now->addDays(3));
    Admin::delegation($this->scenario, $this->bruno, $this->carla, $now->addDays(5), $now->addDays(8));
    Admin::delegation($this->scenario, $this->carla, $this->ana, $now->subDays(20), $now->subDays(10));
    Admin::delegation($this->scenario, $this->ana, $this->carla, $now->subDay(), $now->addDays(3), revokedAt: $now->subHour());

    ($this->page)()
        ->assertOk()
        ->assertSeeText('Delegações de aprovação')
        ->assertSeeTextInOrder(['Vigentes (1)', 'Futuras (1)', 'Encerradas (2)'])
        ->assertSeeText('Vigente')
        ->assertSeeText('Futura')
        ->assertSeeText('Encerrada')
        ->assertSeeText('Revogada')
        ->assertSeeText('Viagem a trabalho')
        ->assertSeeText('Registrada por Ana Aprovadora')
        ->assertSeeText('Titular (quem delega)');
});

it('mostra ao aprovador apenas as delegações que deu ou recebeu, sem escolha de titular', function (): void {
    $now = $this->clock->now();
    Admin::delegation($this->scenario, $this->ana, $this->bruno, $now->subDay(), $now->addDays(3));
    Admin::delegation($this->scenario, $this->carla, $this->ana, $now->addDay(), $now->addDays(3));
    Admin::delegation($this->scenario, $this->bruno, $this->carla, $now->subDay(), $now->addDays(3));

    ($this->page)($this->ana)
        ->assertOk()
        ->assertSeeTextInOrder(['Vigentes (1)', 'Futuras (1)', 'Encerradas (0)'])
        ->assertSeeText('Titular: você (Ana Aprovadora)')
        ->assertDontSeeText('Titular (quem delega)')
        ->assertDontSeeText('Regras de aprovação');
});

it('nega a tela a quem não aprova nem administra as regras', function (string $who): void {
    $membership = match ($who) {
        'comprador' => $this->purchaser,
        'equipe do distribuidor' => $this->scenario->tenant->ownerMembership,
    };

    ($this->page)($membership)->assertForbidden();
})->with(['comprador', 'equipe do distribuidor']);

it('registra a delegação do administrador pela tela, com erros nos campos', function (): void {
    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)
        ->assertSet('firstDay', $this->today->toDateString())
        ->assertSet('delegatorId', '')
        ->call('save')
        ->assertHasErrors(['delegatorId', 'delegateId', 'lastDay', 'reason'])
        ->set('delegatorId', $this->ana->id)
        ->assertViewHas('delegateOptions', fn (array $options): bool => ! isset($options[$this->ana->id]) && isset($options[$this->bruno->id]))
        ->set('delegateId', $this->ana->id)
        ->set('lastDay', $this->today->addDays(90)->toDateString())
        ->set('reason', 'Férias')
        ->call('save')
        ->assertHasErrors(['delegateId'])
        ->assertSee('Escolha como delegado outra pessoa, diferente do titular.')
        ->set('delegateId', $this->bruno->id)
        ->call('save')
        ->assertHasErrors(['lastDay'])
        ->assertSee('A delegação vale por no máximo 90 dias.')
        ->set('lastDay', $this->today->addDays(4)->toDateString())
        ->set('firstDay', $this->today->subDay()->toDateString())
        ->call('save')
        ->assertHasErrors(['firstDay'])
        ->set('firstDay', $this->today->toDateString())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Delegação de Ana Aprovadora para Bruno Aprovador registrada')
        ->assertViewHas('active', static fn (array $active): bool => count($active) === 1)
        ->assertSet('delegatorId', '')
        ->assertSet('reason', ''));

    expect(($this->stored)())->toBe([[$this->ana->id, $this->bruno->id, false]]);
});

it('deixa o aprovador delegar somente as próprias aprovações, mesmo com o titular adulterado', function (): void {
    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)
        ->assertSet('delegatorId', $this->ana->id)
        ->set('delegatorId', $this->bruno->id)
        ->set('delegateId', $this->carla->id)
        ->set('lastDay', $this->today->addDays(2)->toDateString())
        ->set('reason', 'Viagem a trabalho')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Delegação de Ana Aprovadora para Carla Aprovadora registrada'), $this->ana);

    expect(($this->stored)())->toBe([[$this->ana->id, $this->carla->id, false]]);
});

it('revoga pela tela e recusa a revogação por quem não é titular nem administrador', function (): void {
    $now = $this->clock->now();
    $own = Admin::delegation($this->scenario, $this->ana, $this->bruno, $now->subDay(), $now->addDays(3));
    $others = Admin::delegation($this->scenario, $this->carla, $this->ana, $now->subDay(), $now->addDays(3));

    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)
        ->call('revoke', $own->id)
        ->assertSee('Delegação de Ana Aprovadora para Bruno Aprovador revogada.')
        ->call('revoke', $own->id)
        ->assertSee('Esta delegação já foi revogada.'), $this->ana);

    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)->call('revoke', $others->id)->assertForbidden(), $this->ana);

    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)
        ->call('revoke', $others->id)
        ->assertSee('Delegação de Carla Aprovadora para Ana Aprovadora revogada.'));

    expect(($this->stored)())->toBe([[$this->ana->id, $this->bruno->id, true], [$this->carla->id, $this->ana->id, true]]);
});

it('trata delegação adulterada ou de outra empresa como inexistente e protege as mensagens', function (): void {
    $other = $this->scenario->tenant->addBuyerCompany();
    $foreign = Admin::delegation(
        $this->scenario,
        $this->scenario->tenant->addBuyerUser($other, SystemRole::Approver),
        $this->scenario->tenant->addBuyerUser($other, SystemRole::Approver),
        $this->clock->now(),
        $this->clock->now()->addDay(),
    );

    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)->call('revoke', 'delegacao-1')->assertNotFound());
    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)->call('revoke', $foreign->id)->assertNotFound());
    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)->call('revoke', (string) Str::uuid7())->assertNotFound());
    ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)
        ->set('delegatorId', 'titular-1')
        ->set('delegateId', $this->bruno->id)
        ->set('lastDay', $this->today->toDateString())
        ->set('reason', 'Viagem a trabalho')
        ->call('save')
        ->assertNotFound());

    expect(fn () => ($this->as)(fn () => Livewire::test(ApprovalDelegationsIndex::class)->set('message', 'Falso')))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and($this->scenario->system(static fn () => ApprovalDelegation::query()->whereKey($foreign->id)->value('revoked_at')))->toBeNull();
});
