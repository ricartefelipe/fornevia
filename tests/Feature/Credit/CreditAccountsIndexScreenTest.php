<?php

declare(strict_types=1);

use App\Enums\Credit\CreditMovementType;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Credit\CreditAccountsIndex;
use Livewire\Livewire;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Lista de crédito por empresa compradora: valores da posição, empresas sem
| conta e filtros (situação, disponível abaixo de um valor e busca).
*/

beforeEach(function (): void {
    Fx::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->finance = $this->tenant->addStaff(SystemRole::Finance);
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);

    $alpha = Fx::company($this->tenant, 'Alfa Materiais');
    $beta = Fx::company($this->tenant, 'Beta Construções');
    Fx::company($this->tenant, 'Delta Sem Conta');

    Fx::open($this->tenant, $alpha, '10.000,00');
    $betaAccount = Fx::open($this->tenant, $beta, '5.000,00');
    Fx::movement($this->tenant, $betaAccount, CreditMovementType::OrderCommitment, 300_000, 0);
    Fx::movement($this->tenant, $betaAccount, CreditMovementType::Invoicing, 0, 100_000);
    Fx::grant($this->tenant, $beta, '1.500,00', '2026-11-18', '2026-11-30');
});

it('exibe limite, exceções, comprometido, recebível, exposição, disponível e situação', function (): void {
    Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(CreditAccountsIndex::class)
        ->assertSeeInOrder(['Alfa Materiais', 'R$ 10.000,00', 'Ativa'])
        ->assertSeeInOrder(['Beta Construções', 'R$ 5.000,00', 'R$ 1.500,00', 'R$ 3.000,00', 'R$ 1.000,00', 'R$ 4.000,00', 'R$ 2.500,00', 'Ativa'])
        ->assertSeeInOrder(['Delta Sem Conta', 'Sem conta de crédito: pedidos a prazo são recusados.', 'Sem conta'])
        ->assertSee('Ver conta')
        ->assertSee('Ver detalhes')
        ->assertDontSee('Abrir conta'));
});

it('oferece abrir conta para empresas sem conta a quem gerencia crédito', function (): void {
    Fx::asMember($this->tenant, $this->finance, static fn () => Livewire::test(CreditAccountsIndex::class)
        ->assertSee('Abrir conta')
        ->set('situation', 'none')
        ->assertSee('Delta Sem Conta')
        ->assertDontSee('Alfa Materiais'));
});

it('filtra por disponível abaixo de um valor e por busca', function (): void {
    Fx::asMember($this->tenant, $this->finance, static fn () => Livewire::test(CreditAccountsIndex::class)
        ->set('availableBelow', '2.500,01')
        ->assertHasNoErrors()
        ->assertSee('Beta Construções')
        ->assertDontSee('Alfa Materiais')
        ->assertDontSee('Delta Sem Conta')
        ->set('availableBelow', '2500')
        ->assertSee('Nenhuma empresa encontrada')
        ->call('clearFilters')
        ->assertSet('availableBelow', '')
        ->set('search', 'alfa')
        ->assertSee('Alfa Materiais')
        ->assertDontSee('Beta Construções'));
});

it('mostra erro no campo para valor inválido e ignora o filtro', function (): void {
    Fx::asMember($this->tenant, $this->finance, static fn () => Livewire::test(CreditAccountsIndex::class)
        ->set('availableBelow', 'mil reais')
        ->assertHasErrors(['availableBelow'])
        ->assertSee('Informe um valor em reais, por exemplo 10.000,00.')
        ->assertSee('Alfa Materiais')
        ->assertSee('Beta Construções')
        ->set('availableBelow', '99999999999999999,00')
        ->assertSee('Valor acima do limite suportado.'));
});

it('lê os filtros da URL', function (): void {
    Fx::asMember($this->tenant, $this->finance, static fn () => Livewire::withQueryParams(['situacao' => 'none', 'busca' => 'delta'])
        ->test(CreditAccountsIndex::class)
        ->assertSet('situation', 'none')
        ->assertSee('Delta Sem Conta')
        ->assertDontSee('Beta Construções'));
});
