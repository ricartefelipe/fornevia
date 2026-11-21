<?php

declare(strict_types=1);

use App\Actions\Credit\BlockCreditAccount;
use App\Actions\Credit\ListCreditAccounts;
use App\Actions\Credit\RevokeCreditException;
use App\Data\Credit\CreditAccountFilters;
use App\Data\Credit\CreditAccountSummary;
use App\Domain\Credit\Exceptions\CreditNotApproved;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Identity\SystemRole;
use App\Services\Credit\CreditLedger;
use Illuminate\Support\Str;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Fórmula de RN-080 na equipe e no checkout:
|   exposição  = comprometido + recebível
|   disponível = limite + exceções vigentes − exposição
| A lista (consulta em lote) e o detalhe (CreditLedger) chegam aos mesmos
| valores, e o checkout aprova exatamente até o disponível exibido.
*/

beforeEach(function (): void {
    Fx::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->finance = $this->tenant->addStaff(SystemRole::Finance);

    $this->list = fn (CreditAccountFilters $filters = new CreditAccountFilters): array => $this->tenant->actingAs(
        $this->finance,
        static fn (): array => app(ListCreditAccounts::class)->handle($filters)->getCollection()->all(),
    );
});

it('calcula exposição e disponível com exceções vigentes, sem contar vencidas, agendadas ou revogadas', function (): void {
    $company = Fx::company($this->tenant, 'Construtora Horizonte');
    $account = Fx::open($this->tenant, $company, '100.000,00');

    Fx::movement($this->tenant, $account, CreditMovementType::OrderCommitment, 4_000_000, 0);
    Fx::movement($this->tenant, $account, CreditMovementType::OrderCommitment, 1_500_000, 0);
    // Faturamento transfere 1.000.000 de comprometido para recebível (RN-081).
    Fx::movement($this->tenant, $account, CreditMovementType::Invoicing, -1_000_000, 1_000_000);
    Fx::movement($this->tenant, $account, CreditMovementType::PaymentSettlement, 0, -250_000);

    Fx::grant($this->tenant, $company, '20.000,00', '2026-11-18', '2026-11-30');
    $revoked = Fx::grant($this->tenant, $company, '7.000,00', '2026-11-18', '2026-11-30');
    Fx::grant($this->tenant, $company, '9.000,00', '2026-11-25', '2026-11-30');
    $this->tenant->actingAs($this->finance, static fn () => app(RevokeCreditException::class)->handle($revoked, 'Exceção concedida em duplicidade'));

    // Exceção vencida: concedida no passado e encerrada antes de agora.
    Fx::freezeClock('2026-11-10T15:00:00Z');
    Fx::grant($this->tenant, $company, '50.000,00', '2026-11-10', '2026-11-15');
    Fx::freezeClock();

    $position = Fx::position($this->tenant, $company);

    expect($position->limit->cents)->toBe(10_000_000)
        ->and($position->activeExceptions->cents)->toBe(2_000_000)
        ->and($position->committed->cents)->toBe(4_500_000)
        ->and($position->receivable->cents)->toBe(750_000)
        ->and($position->exposure()->cents)->toBe(5_250_000)
        ->and($position->available()->cents)->toBe(10_000_000 + 2_000_000 - 5_250_000);

    $row = collect(($this->list)())->sole();

    expect($row)->toBeInstanceOf(CreditAccountSummary::class)
        ->and($row->position?->limit->cents)->toBe($position->limit->cents)
        ->and($row->position?->activeExceptions->cents)->toBe($position->activeExceptions->cents)
        ->and($row->position?->committed->cents)->toBe($position->committed->cents)
        ->and($row->position?->receivable->cents)->toBe($position->receivable->cents)
        ->and($row->position?->exposure()->cents)->toBe($position->exposure()->cents)
        ->and($row->position?->available()->cents)->toBe($position->available()->cents);
});

it('aprova no checkout exatamente até o disponível exibido à equipe', function (): void {
    $company = Fx::company($this->tenant, 'Mercado Central');
    $account = Fx::open($this->tenant, $company, '1.000,00');
    Fx::grant($this->tenant, $company, '250,00', '2026-11-18', '2026-11-18');
    Fx::movement($this->tenant, $account, CreditMovementType::OrderCommitment, 50_000, 0);

    $available = collect(($this->list)())->sole()->position?->available();

    expect($available?->cents)->toBe(75_000);

    $commit = fn (int $cents) => $this->tenant->system(static fn () => app(CreditLedger::class)
        ->commitForOrder($company->id, (string) Str::uuid7(), Money::ofCents($cents)));

    expect(fn () => $commit(75_001))->toThrow(CreditNotApproved::class);

    $commit(75_000);

    expect(Fx::position($this->tenant, $company)->available()->cents)->toBe(0);
});

it('lista empresas sem conta e filtra por situação, disponível abaixo de um valor e busca', function (): void {
    $alpha = Fx::company($this->tenant, 'Alfa Materiais', ['document' => '11222333000181']);
    $beta = Fx::company($this->tenant, 'Beta Construções');
    $gamma = Fx::company($this->tenant, 'Gama Engenharia');
    Fx::company($this->tenant, 'Delta Sem Conta');

    Fx::open($this->tenant, $alpha, '10.000,00');
    $betaAccount = Fx::open($this->tenant, $beta, '5.000,00');
    Fx::open($this->tenant, $gamma, '2.000,00');
    Fx::movement($this->tenant, $betaAccount, CreditMovementType::OrderCommitment, 300_000, 0);
    Fx::grant($this->tenant, $gamma, '1.000,00', '2026-11-18', '2026-11-30');
    $this->tenant->actingAs($this->finance, static fn () => app(BlockCreditAccount::class)->handle($gamma, 'Cheques devolvidos'));

    $names = fn (CreditAccountFilters $filters = new CreditAccountFilters): array => array_map(
        static fn (CreditAccountSummary $row): string => $row->tradeName,
        ($this->list)($filters),
    );

    expect($names())->toBe(['Alfa Materiais', 'Beta Construções', 'Delta Sem Conta', 'Gama Engenharia'])
        ->and(collect(($this->list)())->firstWhere('tradeName', 'Delta Sem Conta')?->hasAccount())->toBeFalse()
        ->and($names(new CreditAccountFilters(situation: 'active')))->toBe(['Alfa Materiais', 'Beta Construções'])
        ->and($names(new CreditAccountFilters(situation: 'blocked')))->toBe(['Gama Engenharia'])
        ->and($names(new CreditAccountFilters(situation: CreditAccountFilters::WITHOUT_ACCOUNT)))->toBe(['Delta Sem Conta'])
        // Disponíveis: Alfa 10.000, Beta 2.000, Gama 3.000 (2.000 + 1.000 de exceção); o filtro é estrito.
        ->and($names(new CreditAccountFilters(availableBelow: Money::parse('3.000,00'))))->toBe(['Beta Construções'])
        ->and($names(new CreditAccountFilters(availableBelow: Money::parse('3.000,01'))))->toBe(['Beta Construções', 'Gama Engenharia'])
        ->and($names(new CreditAccountFilters(search: 'engenharia')))->toBe(['Gama Engenharia'])
        ->and($names(new CreditAccountFilters(search: '11.222.333/0001-81')))->toBe(['Alfa Materiais'])
        ->and($names(new CreditAccountFilters(search: '100%_')))->toBe([]);
});
