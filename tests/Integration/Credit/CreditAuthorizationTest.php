<?php

declare(strict_types=1);

use App\Actions\Credit\BlockCreditAccount;
use App\Actions\Credit\ChangeCreditLimit;
use App\Actions\Credit\ChangeOverdueTolerance;
use App\Actions\Credit\CreditAccountRecords;
use App\Actions\Credit\GrantCreditException;
use App\Actions\Credit\ListCreditAccounts;
use App\Actions\Credit\OpenCreditAccount;
use App\Actions\Credit\RevokeCreditException;
use App\Actions\Credit\UnblockCreditAccount;
use App\Data\Credit\CreditAccountFilters;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Identity\SystemRole;
use App\Models\Credit\CreditAccount;
use App\Models\Credit\CreditException;
use App\Models\Credit\CreditMovement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Autorização (credit.view para consultar, credit.manage para alterar),
| isolamento entre distribuidores e entre empresas compradoras: o
| comprador não lê nem altera dados de crédito pelo banco (ADR-020).
*/

beforeEach(function (): void {
    Fx::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->company = Fx::company($this->tenant, 'Construtora Serra Azul');
    $this->other = Fx::company($this->tenant, 'Revenda Vale Verde');
    $this->account = Fx::open($this->tenant, $this->company, '10.000,00');
    $this->otherAccount = Fx::open($this->tenant, $this->other, '3.000,00');
    $this->exception = Fx::grant($this->tenant, $this->company, '1.000,00', '2026-11-18', '2026-11-30');
    Fx::grant($this->tenant, $this->other, '500,00', '2026-11-18', '2026-11-30');
    Fx::movement($this->tenant, $this->account, CreditMovementType::OrderCommitment, 200_000, 0);
    Fx::movement($this->tenant, $this->otherAccount, CreditMovementType::OrderCommitment, 100_000, 0);

    $this->withoutAccount = Fx::company($this->tenant, 'Empresa sem conta');

    $this->operations = [
        'abertura' => fn () => app(OpenCreditAccount::class)->handle($this->withoutAccount, Money::parse('1,00'), 0, 'Abertura sem permissão'),
        'limite' => fn () => app(ChangeCreditLimit::class)->handle($this->company, Money::parse('99.000,00'), 'Aumento sem permissão'),
        'bloqueio' => fn () => app(BlockCreditAccount::class)->handle($this->company, 'Bloqueio sem permissão'),
        'desbloqueio' => fn () => app(UnblockCreditAccount::class)->handle($this->company, 'Desbloqueio sem permissão'),
        'tolerância' => fn () => app(ChangeOverdueTolerance::class)->handle($this->company, 30, 'Tolerância sem permissão'),
        'exceção' => fn () => app(GrantCreditException::class)->handle($this->company, Money::parse('9.000,00'), CarbonImmutable::parse('2026-11-18'), CarbonImmutable::parse('2026-11-20'), 'Exceção sem permissão'),
        'revogação' => fn () => app(RevokeCreditException::class)->handle($this->exception, 'Revogação sem permissão'),
    ];

    $this->snapshot = fn (): array => [
        Fx::limitChanges($this->tenant),
        Fx::creditAuditCount($this->tenant),
        Fx::position($this->tenant, $this->company)->available()->cents,
    ];
});

it('nega alterações a perfis sem credit.manage, sem efeito algum', function (SystemRole $role): void {
    $membership = $this->tenant->addStaff($role);
    $before = ($this->snapshot)();

    foreach ($this->operations as $name => $operation) {
        expect(fn () => $this->tenant->actingAs($membership, $operation))
            ->toThrow(AuthorizationException::class, 'Permissão necessária: Gerenciar limites e exceções de crédito.');
    }

    expect(($this->snapshot)())->toBe($before);
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'representante' => [SystemRole::SalesRep],
    'consulta' => [SystemRole::Viewer],
]);

it('permite consultar com credit.view e nega a quem não tem', function (): void {
    $viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $warehouse = $this->tenant->addStaff(SystemRole::Warehouse);

    $rows = $this->tenant->actingAs($viewer, static fn () => app(ListCreditAccounts::class)->handle(new CreditAccountFilters)->total());
    $changes = $this->tenant->actingAs($viewer, fn () => app(CreditAccountRecords::class)->limitChanges($this->account)->total());

    expect($rows)->toBe(3)->and($changes)->toBe(1);

    expect(fn () => $this->tenant->actingAs($warehouse, static fn () => app(ListCreditAccounts::class)->handle(new CreditAccountFilters)))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->tenant->actingAs($warehouse, fn () => app(CreditAccountRecords::class)->movements($this->account)))
        ->toThrow(AuthorizationException::class);
});

it('isola os distribuidores: a equipe de outro tenant não vê nem altera a conta', function (): void {
    $foreign = TenantScenario::create();
    $foreignFinance = $foreign->addStaff(SystemRole::Finance);
    $foreignCompany = Fx::company($foreign, 'Empresa do outro distribuidor');
    Fx::open($foreign, $foreignCompany, '1,00');
    $before = ($this->snapshot)();

    $listed = $foreign->actingAs($foreignFinance, static fn () => app(ListCreditAccounts::class)
        ->handle(new CreditAccountFilters)->getCollection()->pluck('tradeName')->all());

    expect($listed)->toBe(['Empresa do outro distribuidor']);

    foreach (['abertura', 'limite', 'bloqueio', 'tolerância', 'exceção', 'revogação'] as $name) {
        expect(fn () => $foreign->actingAs($foreignFinance, $this->operations[$name]))->toThrow(ModelNotFoundException::class);
    }

    expect(($this->snapshot)())->toBe($before)
        ->and($foreign->actingAs($foreignFinance, static fn (): int => CreditAccount::query()->count()
            + CreditException::query()->count() + CreditMovement::query()->count()))->toBe(1);
});

it('não deixa o comprador ler dados de crédito pelo banco, nem os da própria empresa', function (): void {
    $buyer = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);

    $seen = $this->tenant->actingAs($buyer, static fn (): array => [
        CreditAccount::query()->pluck('buyer_company_id')->all(),
        CreditException::query()->pluck('buyer_company_id')->unique()->values()->all(),
        CreditMovement::query()->pluck('buyer_company_id')->unique()->values()->all(),
        DB::table('credit_accounts')->count(),
    ]);

    expect($seen)->toBe([[], [], [], 0]);
});

it('impede o comprador de alterar a conta pelas Actions e pelo banco', function (): void {
    $buyer = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $before = ($this->snapshot)();

    foreach (['limite', 'bloqueio', 'exceção', 'revogação'] as $name) {
        expect(fn () => $this->tenant->actingAs($buyer, $this->operations[$name]))->toThrow(AuthorizationException::class);
    }

    expect(fn () => $this->tenant->actingAs($buyer, static fn () => app(ListCreditAccounts::class)->handle(new CreditAccountFilters)))
        ->toThrow(AuthorizationException::class);

    // Políticas restritivas de escrita: nenhuma linha visível para UPDATE.
    $updated = $this->tenant->actingAs($buyer, fn (): int => DB::table('credit_accounts')
        ->where('id', $this->account->id)
        ->update(['limit_cents' => 999_999_999, 'updated_at' => now()]));

    expect($updated)->toBe(0);

    expect(fn () => $this->tenant->actingAs($buyer, fn () => DB::table('credit_exceptions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->organization->id,
        'credit_account_id' => $this->account->id,
        'buyer_company_id' => $this->company->id,
        'amount_cents' => 1_000_000,
        'valid_from' => now(),
        'valid_until' => now()->addDay(),
        'reason' => 'Autoconcessão pelo portal',
        'granted_by_user_id' => $buyer->user_id,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(fn () => $this->tenant->actingAs($buyer, fn () => DB::table('credit_movements')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->organization->id,
        'credit_account_id' => $this->account->id,
        'buyer_company_id' => $this->company->id,
        'type' => CreditMovementType::PaymentSettlement->value,
        'committed_delta' => 0,
        'receivable_delta' => -1,
        'reference_type' => 'payment',
        'reference_id' => (string) Str::uuid7(),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(($this->snapshot)())->toBe($before);
});
