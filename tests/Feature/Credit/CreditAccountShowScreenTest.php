<?php

declare(strict_types=1);

use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Credit\CreditAccountShow;
use App\Livewire\Staff\Credit\CreditExceptions;
use App\Livewire\Staff\Credit\CreditHistory;
use App\Models\Credit\CreditException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Detalhe da conta de crédito pela tela: abertura, limite, bloqueio,
| tolerância e exceções com justificativa; perfis de consulta não alteram.
*/

beforeEach(function (): void {
    Fx::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->finance = $this->tenant->addStaff(SystemRole::Finance);
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $this->company = Fx::company($this->tenant, 'Construtora Serra Azul');

    $this->show = fn ($membership, Closure $steps) => Fx::asMember(
        $this->tenant,
        $membership,
        fn () => $steps(Livewire::test(CreditAccountShow::class, ['buyer' => $this->company])),
    );
});

it('abre a conta pela tela com limite, tolerância e justificativa', function (): void {
    ($this->show)($this->finance, static fn ($screen) => $screen
        ->assertSee('Esta empresa ainda não tem conta de crédito')
        ->set('openLimit', '25.000,00')
        ->set('openTolerance', '7')
        ->set('openReason', '')
        ->call('open')
        ->assertHasErrors(['openReason' => 'required'])
        ->set('openReason', 'Cliente com bom histórico de pagamentos')
        ->call('open')
        ->assertHasNoErrors()
        ->assertSet('message', 'Conta de crédito aberta com limite de R$ 25.000,00.')
        ->assertSet('newLimit', '25000,00')
        ->assertSet('tolerance', '7')
        ->assertSee('Posição de crédito')
        ->assertSee('Novos pedidos a prazo são aceitos até R$ 25.000,00.'));

    $account = Fx::account($this->tenant, $this->company);

    expect($account?->limit_cents->cents)->toBe(2_500_000)
        ->and($account?->overdue_tolerance_days)->toBe(7)
        ->and(Fx::limitChanges($this->tenant)[0][4])->toBe('Cliente com bom histórico de pagamentos');
});

it('mostra no campo o valor inválido e a abertura repetida', function (): void {
    ($this->show)($this->finance, static fn ($screen) => $screen
        ->set('openLimit', '12,345')
        ->set('openReason', 'Limite com casas demais')
        ->call('open')
        ->assertHasErrors(['openLimit'])
        ->assertSee('Informe um valor em reais, por exemplo 1.234,56.')
        ->set('openLimit', '1.000,00')
        ->call('open')
        ->assertHasNoErrors()
        // Requisição repetida (por exemplo, outra aba aberta antes da abertura).
        ->set('openLimit', '2.000,00')
        ->set('openReason', 'Segunda abertura da mesma conta')
        ->call('open')
        ->assertHasErrors(['account'])
        ->assertSee('A empresa Construtora Serra Azul já tem conta de crédito comercial.'));

    expect(Fx::account($this->tenant, $this->company)?->limit_cents->cents)->toBe(100_000);
});

it('altera o limite com justificativa e avisa quando a exposição supera o novo limite', function (): void {
    $account = Fx::open($this->tenant, $this->company, '50.000,00');
    Fx::movement($this->tenant, $account, CreditMovementType::OrderCommitment, 3_000_000, 0);

    ($this->show)($this->finance, static fn ($screen) => $screen
        ->assertSet('newLimit', '50000,00')
        ->set('newLimit', '20.000,00')
        ->call('changeLimit')
        ->assertHasErrors(['limitReason' => 'required'])
        ->set('limitReason', 'Redução por atrasos recorrentes')
        ->call('changeLimit')
        ->assertHasNoErrors()
        ->assertSet('message', 'Limite alterado para R$ 20.000,00. A exposição atual supera o novo limite: novos pedidos a prazo serão recusados até haver saldo disponível.')
        ->assertSee('-R$ 10.000,00')
        ->assertSee('Novos pedidos a prazo são recusados: não há limite disponível.')
        ->set('limitReason', 'Mesmo limite outra vez')
        ->call('changeLimit')
        ->assertHasErrors(['newLimit'])
        ->assertSee('O limite já é R$ 20.000,00.'));

    expect(Fx::account($this->tenant, $this->company)?->limit_cents->cents)->toBe(2_000_000);
});

it('bloqueia e desbloqueia pela tela com motivo', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');

    ($this->show)($this->finance, static fn ($screen) => $screen
        ->call('block')
        ->assertHasErrors(['statusReason' => 'required'])
        ->set('statusReason', 'Cheques devolvidos pelo banco')
        ->call('block')
        ->assertHasNoErrors()
        ->assertSet('message', 'Crédito bloqueado. Novos pedidos a prazo desta empresa serão recusados.')
        ->assertSee('Motivo do bloqueio:')
        ->assertSee('Cheques devolvidos pelo banco')
        ->assertSee('Novos pedidos a prazo são recusados: o crédito está bloqueado.')
        ->assertSee('Desbloquear crédito')
        ->set('statusReason', 'Cheques reapresentados e compensados')
        ->call('unblock')
        ->assertHasNoErrors()
        ->assertSee('Bloquear crédito'));

    expect(Fx::account($this->tenant, $this->company)?->status)->toBe(CreditAccountStatus::Active)
        ->and(array_column(Fx::limitChanges($this->tenant), 3))->toBe(['active', 'blocked', 'active']);
});

it('altera a tolerância de vencidos pela tela', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');

    ($this->show)($this->finance, static fn ($screen) => $screen
        ->set('tolerance', '400')
        ->set('toleranceReason', 'Tolerância acima do permitido')
        ->call('changeTolerance')
        ->assertHasErrors(['tolerance'])
        ->set('tolerance', '20')
        ->call('changeTolerance')
        ->assertHasNoErrors()
        ->assertSet('message', 'Tolerância de vencidos alterada para 20 dia(s).')
        ->assertSee('Tolerância de vencidos: 20 dia(s).'));
});

it('concede e revoga exceção pela seção de exceções', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');

    Fx::asMember($this->tenant, $this->finance, fn () => Livewire::test(CreditExceptions::class, ['buyerId' => $this->company->id])
        ->assertSet('firstDay', '2026-11-18')
        ->set('amount', '2.000,00')
        ->set('lastDay', '2026-11-30')
        ->call('grant')
        ->assertHasErrors(['reason' => 'required'])
        ->set('reason', 'Obra emergencial do cliente')
        ->call('grant')
        ->assertHasNoErrors()
        ->assertSet('message', 'Exceção de R$ 2.000,00 concedida.')
        ->assertDispatched('credit-account-updated')
        ->assertSee('18/11/2026 a 30/11/2026')
        ->assertSee('Vigente')
        ->assertSee('Obra emergencial do cliente'));

    expect(Fx::position($this->tenant, $this->company)->activeExceptions->cents)->toBe(200_000);

    $exception = $this->tenant->system(static fn (): CreditException => CreditException::query()->sole());

    Fx::asMember($this->tenant, $this->finance, fn () => Livewire::test(CreditExceptions::class, ['buyerId' => $this->company->id])
        ->call('revoke', $exception->id)
        ->assertHasErrors(['revokeReason'])
        ->set('revokeReason', 'Obra concluída antes do previsto')
        ->call('revoke', $exception->id)
        ->assertHasNoErrors()
        ->assertSet('message', 'Exceção de R$ 2.000,00 revogada.')
        ->assertSee('Revogada')
        ->assertSee('Obra concluída antes do previsto'));

    expect(Fx::position($this->tenant, $this->company)->activeExceptions->cents)->toBe(0);
});

it('mostra no campo a vigência recusada pela regra', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');

    Fx::asMember($this->tenant, $this->finance, fn () => Livewire::test(CreditExceptions::class, ['buyerId' => $this->company->id])
        ->set('amount', '100,00')
        ->set('firstDay', '2026-11-17')
        ->set('lastDay', '2026-11-20')
        ->set('reason', 'Vigência retroativa')
        ->call('grant')
        ->assertHasErrors(['firstDay'])
        ->assertSee('A vigência da exceção não pode começar antes de hoje.')
        ->set('firstDay', '0000-01-01')
        ->call('grant')
        ->assertHasErrors(['firstDay']));
});

it('recusa revogar exceção de outra empresa pela seção de exceções', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');
    $other = Fx::company($this->tenant, 'Revenda Vale Verde');
    Fx::open($this->tenant, $other, '1.000,00');
    $foreign = Fx::grant($this->tenant, $other, '300,00', '2026-11-18', '2026-11-30');

    Fx::asMember($this->tenant, $this->finance, fn () => Livewire::test(CreditExceptions::class, ['buyerId' => $this->company->id])
        ->set('revokeReason', 'Tentativa em outra empresa')
        ->call('revoke', $foreign->id)
        ->assertNotFound());

    Fx::asMember($this->tenant, $this->finance, fn () => Livewire::test(CreditExceptions::class, ['buyerId' => $this->company->id])
        ->call('revoke', 'nao-e-uuid')
        ->assertNotFound());

    expect(Fx::position($this->tenant, $other)->activeExceptions->cents)->toBe(30_000);
});

it('refaz a autorização nas ações de perfis apenas de consulta', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');
    $exception = Fx::grant($this->tenant, $this->company, '300,00', '2026-11-18', '2026-11-30');

    ($this->show)($this->viewer, static fn ($screen) => $screen
        ->assertDontSee('Alterar limite')
        ->set('newLimit', '90.000,00')
        ->set('limitReason', 'Aumento sem permissão')
        ->call('changeLimit')
        ->assertForbidden());

    ($this->show)($this->viewer, static fn ($screen) => $screen
        ->set('statusReason', 'Bloqueio sem permissão')
        ->call('block')
        ->assertForbidden());

    Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(CreditExceptions::class, ['buyerId' => $this->company->id])
        ->assertDontSee('Conceder exceção')
        ->set('revokeReason', 'Revogação sem permissão')
        ->call('revoke', $exception->id)
        ->assertForbidden());

    $account = Fx::account($this->tenant, $this->company);

    expect($account?->limit_cents->cents)->toBe(1_000_000)
        ->and($account?->status)->toBe(CreditAccountStatus::Active)
        ->and(Fx::position($this->tenant, $this->company)->activeExceptions->cents)->toBe(30_000);
});

it('não permite trocar a empresa da tela pelo cliente', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');
    $other = Fx::company($this->tenant, 'Revenda Vale Verde');

    ($this->show)($this->finance, static fn ($screen) => $screen->set('buyerId', $other->id));
})->throws(CannotUpdateLockedPropertyException::class);

it('lista movimentos e alterações de limite no histórico', function (): void {
    $account = Fx::open($this->tenant, $this->company, '10.000,00');
    Fx::movement($this->tenant, $account, CreditMovementType::OrderCommitment, 150_000, 0);
    Fx::movement($this->tenant, $account, CreditMovementType::Invoicing, -150_000, 150_000);

    Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(CreditHistory::class, ['buyerId' => $this->company->id])
        ->assertSee('Limite de R$ 0,00 para R$ 10.000,00')
        ->assertSee('Limite aprovado pelo comitê de crédito')
        ->assertSee('Faturamento')
        ->assertSee('Comprometimento de pedido')
        ->assertSee('-R$ 1.500,00')
        ->assertSee('+R$ 1.500,00'));
});
