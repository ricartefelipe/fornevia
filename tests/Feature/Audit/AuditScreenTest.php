<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Audit\AuditIndex;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

/*
| Tela de auditoria: somente leitura, do mais recente ao mais antigo, com
| filtros por ação ou grupo, autor, registro e período; restrita a
| audit.view e ao próprio distribuidor.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $this->finance = $this->tenant->addStaff(SystemRole::Finance, User::factory()->withTwoFactor()->create(['name' => 'Fernanda Financeiro']));
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager, User::factory()->create(['name' => 'Gustavo Gerente']));

    // Registros são somente inclusão: a data vem do relógio no momento da gravação.
    $record = function (Membership $member, string $action, array $data, ?string $reason, string $at): void {
        $this->travelTo(Carbon\CarbonImmutable::parse($at));
        $this->tenant->actingAs($member, static fn () => app(AuditRecorder::class)->record($action, null, $data, $reason));
        $this->travelBack();
    };
    $record($this->manager, 'order.confirmed', ['number' => 'PED-000101'], null, '2026-11-03T13:00:00Z');
    $record($this->finance, 'credit.limit_changed', ['limit_cents' => 500_000], 'Revisão trimestral', '2026-11-10T14:00:00Z');
    $record($this->manager, 'order.cancelled', ['number' => 'PED-000102'], 'Pedido duplicado', '2026-11-10T02:30:00Z');

    $this->as = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->finance;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
    $this->page = function (?Membership $membership = null): TestResponse {
        $membership ??= $this->finance;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get(route('audit.index'));
    };
});

it('lista do mais recente ao mais antigo com autor, motivo e detalhes', function (): void {
    ($this->page)()->assertOk()->assertSeeTextInOrder(['credit.limit_changed', 'order.cancelled', 'order.confirmed'])
        ->assertSeeText('Revisão trimestral')->assertSeeText('Fernanda Financeiro')->assertSeeText('PED-000101');
});

it('filtra por grupo ou ação, autor e período no fuso comercial', function (): void {
    // Os nomes das ações também aparecem no seletor: a conferência usa os detalhes.
    ($this->as)(fn () => Livewire::test(AuditIndex::class)
        ->set('action', 'order')->assertSee('PED-000101')->assertSee('PED-000102')->assertDontSee('Revisão trimestral')
        ->set('action', 'order.cancelled')->assertSee('Pedido duplicado')->assertDontSee('PED-000101')
        ->set('action', '')->set('actor', $this->finance->user_id)->assertSee('Revisão trimestral')->assertDontSee('PED-000102')
        ->set('actor', '')->set('dateFrom', '2026-11-09')->set('dateUntil', '2026-11-09')
        // 02:30 UTC de 10/11 ainda é 09/11 no fuso comercial (UTC−3).
        ->assertSee('PED-000102')->assertDontSee('Revisão trimestral')->assertDontSee('PED-000101')
        ->call('clearFilters')->assertSee('PED-000101'));
});

it('ignora filtros inválidos vindos da URL', function (): void {
    ($this->as)(fn () => Livewire::withQueryParams(['acao' => "x' or 1=1", 'autor' => 'nao-e-uuid', 'de' => '2026-02-30'])
        ->test(AuditIndex::class)->assertSee('order.confirmed')->assertSee('credit.limit_changed'));
});

it('exige audit.view e mostra só o próprio distribuidor', function (): void {
    ($this->page)($this->tenant->addStaff(SystemRole::Warehouse))->assertForbidden();

    $other = TenantScenario::create(name: 'Outra Distribuidora');
    $owner = $other->ownerMembership;
    $other->actingAs($owner, fn () => app(AuditRecorder::class)->record('outro.tenant'));

    ($this->page)()->assertOk()->assertDontSeeText('outro.tenant');
});

it('aceita o id com espaços em volta e guarda as opções de filtro', function (): void {
    $id = $this->tenant->actingAs($this->manager, fn (): string => app(AuditRecorder::class)->record('quote.sent', $this->manager, ['number' => 'COT-000777'])->subject_id);

    ($this->as)(fn () => Livewire::test(AuditIndex::class)->set('subject', '  '.$id.'  ')->assertSee('COT-000777')->assertDontSee('PED-000101'));

    Illuminate\Support\Facades\DB::enableQueryLog();
    ($this->as)(fn () => Livewire::test(AuditIndex::class)->set('action', 'order'));
    $distinct = array_filter(Illuminate\Support\Facades\DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'distinct'));

    expect($distinct)->toBe([]);
});
