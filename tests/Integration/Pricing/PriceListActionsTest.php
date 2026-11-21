<?php

declare(strict_types=1);

use App\Actions\Pricing\ActivatePriceList;
use App\Actions\Pricing\ArchivePriceList;
use App\Actions\Pricing\SavePriceList;
use App\Data\Pricing\PriceListData;
use App\Domain\Pricing\Exceptions\InvalidPriceList;
use App\Domain\Pricing\Exceptions\InvalidPriceListTarget;
use App\Domain\Pricing\Exceptions\InvalidPriceListTransition;
use App\Domain\Pricing\Exceptions\InvalidValidityPeriod;
use App\Domain\Pricing\Exceptions\PriceListCodeTaken;
use App\Domain\Pricing\Exceptions\PriceListNotEditable;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Membership;
use App\Models\Pricing\PriceList;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->save = fn (Membership $actor, ?PriceList $list, PriceListData $data): PriceList => $this->tenant->actingAs(
        $actor,
        static fn (): PriceList => app(SavePriceList::class)->handle($list, $data),
    );

    $this->data = static fn (array $overrides = []): PriceListData => new PriceListData(...[
        'code' => 'atacado-2026',
        'name' => '  Atacado 2026  ',
        'scope' => PriceListScope::Default,
        'validFrom' => CarbonImmutable::parse('2026-12-01'),
        'validUntil' => CarbonImmutable::parse('2026-12-31'),
        'priority' => 5,
        ...$overrides,
    ]);

    $this->stored = fn (string $id): PriceList => $this->tenant->system(static fn (): PriceList => PriceList::query()->findOrFail($id));
});

it('cria a tabela como rascunho, com código normalizado, auditoria e nova versão de preços', function (): void {
    $version = Fx::version($this->tenant);

    $list = ($this->save)($this->manager, null, ($this->data)());
    $stored = ($this->stored)($list->id);

    expect($stored->code)->toBe('ATACADO-2026')
        ->and($stored->name)->toBe('Atacado 2026')
        ->and($stored->status)->toBe(PriceListStatus::Draft)
        ->and($stored->scope)->toBe(PriceListScope::Default)
        ->and($stored->priority)->toBe(5)
        ->and($stored->valid_from->toDateString())->toBe('2026-12-01')
        ->and($stored->valid_until?->toDateString())->toBe('2026-12-31')
        ->and(Fx::version($this->tenant))->toBe($version + 1);

    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'price_list.created')->sole());

    expect($log->subject_type)->toBe('price_list')
        ->and($log->subject_id)->toBe($list->id)
        ->and($log->actor_membership_id)->toBe($this->manager->id)
        ->and($log->data['before'])->toBeNull()
        ->and($log->data['after']['code'])->toBe('ATACADO-2026')
        ->and($log->data['after']['status'])->toBe('draft');
});

it('altera o cabeçalho mantendo a situação e registrando antes e depois', function (): void {
    $list = Fx::priceList($this->tenant, 'VAREJO', priority: 1);

    ($this->save)($this->manager, $list, ($this->data)(['code' => 'VAREJO', 'name' => 'Varejo revisada', 'priority' => 8]));
    $stored = ($this->stored)($list->id);
    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'price_list.updated')->sole());

    expect($stored->status)->toBe(PriceListStatus::Active)
        ->and($stored->name)->toBe('Varejo revisada')
        ->and($stored->priority)->toBe(8)
        ->and($log->data['before']['priority'])->toBe(1)
        ->and($log->data['after']['priority'])->toBe(8);
});

it('vincula a tabela ao grupo ou à empresa do próprio tenant', function (): void {
    $group = Fx::group($this->tenant, 'VAREJO', 'Varejo alimentar');
    $company = Fx::company($this->tenant, 'Mercado Bom Preço');

    $groupList = ($this->save)($this->manager, null, ($this->data)(['code' => 'G1', 'scope' => PriceListScope::Group, 'customerGroupId' => $group->id]));
    $customerList = ($this->save)($this->manager, null, ($this->data)(['code' => 'C1', 'scope' => PriceListScope::Customer, 'buyerCompanyId' => $company->id]));

    expect(($this->stored)($groupList->id)->customer_group_id)->toBe($group->id)
        ->and(($this->stored)($groupList->id)->buyer_company_id)->toBeNull()
        ->and(($this->stored)($customerList->id)->buyer_company_id)->toBe($company->id)
        ->and(($this->stored)($customerList->id)->customer_group_id)->toBeNull();
});

it('recusa alvo incoerente com o escopo, inexistente ou de outro tenant', function (array $overrides, string $field): void {
    $other = TenantScenario::create();
    $foreignGroup = Fx::group($other, 'VAREJO', 'Varejo alimentar');
    $foreignCompany = Fx::company($other, 'Mercado Bom Preço');
    $group = Fx::group($this->tenant, 'FOOD', 'Food service');

    $resolved = array_map(static fn (mixed $value): mixed => match ($value) {
        'foreign-group' => $foreignGroup->id,
        'foreign-company' => $foreignCompany->id,
        'own-group' => $group->id,
        default => $value,
    }, $overrides);

    try {
        ($this->save)($this->manager, null, ($this->data)($resolved));
        $this->fail('A tabela não deveria ter sido criada.');
    } catch (InvalidPriceListTarget $e) {
        expect($e->errorCode())->toBe('invalid_price_list_target')
            ->and($e->details()['field'])->toBe($field);
    }

    expect($this->tenant->system(static fn (): int => PriceList::query()->count()))->toBe(0);
})->with([
    'padrão com grupo' => [['customerGroupId' => 'own-group'], 'scope'],
    'grupo sem grupo' => [['scope' => PriceListScope::Group], 'customer_group_id'],
    'grupo de outro tenant' => [['scope' => PriceListScope::Group, 'customerGroupId' => 'foreign-group'], 'customer_group_id'],
    'grupo com identificador malformado' => [['scope' => PriceListScope::Group, 'customerGroupId' => 'VAREJO'], 'customer_group_id'],
    'grupo com empresa' => [['scope' => PriceListScope::Group, 'customerGroupId' => 'own-group', 'buyerCompanyId' => '01970000-0000-7000-8000-000000000001'], 'customer_group_id'],
    'empresa de outro tenant' => [['scope' => PriceListScope::Customer, 'buyerCompanyId' => 'foreign-company'], 'buyer_company_id'],
    'empresa inexistente' => [['scope' => PriceListScope::Customer, 'buyerCompanyId' => '01970000-0000-7000-8000-000000000002'], 'buyer_company_id'],
]);

it('recusa código duplicado no tenant, mas aceita o mesmo código em outro tenant', function (): void {
    Fx::priceList($this->tenant, 'ATACADO-2026');
    $other = TenantScenario::create();

    expect(fn () => ($this->save)($this->manager, null, ($this->data)(['code' => ' atacado-2026 '])))
        ->toThrow(PriceListCodeTaken::class, 'Já existe uma tabela de preço com o código ATACADO-2026.');

    $foreign = ($this->save)($other->ownerMembership, null, ($this->data)());

    expect($foreign->code)->toBe('ATACADO-2026');
});

it('valida código, nome, prioridade e vigência', function (array $overrides, string $exception, string $message): void {
    expect(fn () => ($this->save)($this->manager, null, ($this->data)($overrides)))->toThrow($exception, $message);
})->with([
    'código vazio' => [['code' => '  '], InvalidPriceList::class, 'Informe um código'],
    'código com espaço' => [['code' => 'TABELA 1'], InvalidPriceList::class, 'Informe um código'],
    'código longo' => [['code' => str_repeat('A', 31)], InvalidPriceList::class, 'Informe um código'],
    'nome vazio' => [['name' => ' '], InvalidPriceList::class, 'Informe um nome'],
    'prioridade fora do limite' => [['priority' => 10_000], InvalidPriceList::class, 'A prioridade deve estar entre'],
    'término antes do início' => [['validUntil' => CarbonImmutable::parse('2026-11-30')], InvalidValidityPeriod::class, 'O término da vigência não pode ser anterior ao início.'],
]);

it('aceita vigência de um único dia e sem término', function (): void {
    $oneDay = ($this->save)($this->manager, null, ($this->data)(['code' => 'DIA', 'validUntil' => CarbonImmutable::parse('2026-12-01')]));
    $open = ($this->save)($this->manager, null, ($this->data)(['code' => 'ABERTA', 'validUntil' => null]));

    expect(($this->stored)($oneDay->id)->valid_until?->toDateString())->toBe('2026-12-01')
        ->and(($this->stored)($open->id)->valid_until)->toBeNull();
});

it('exige pricing.manage para criar, alterar, ativar e arquivar', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $list = Fx::priceList($this->tenant, 'PADRAO', activate: false);
    $version = Fx::version($this->tenant);

    expect(fn () => ($this->save)($actor, null, ($this->data)()))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($actor, $list, ($this->data)(['code' => 'PADRAO'])))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tenant->actingAs($actor, static fn () => app(ActivatePriceList::class)->handle($list)))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tenant->actingAs($actor, static fn () => app(ArchivePriceList::class)->handle($list)))->toThrow(AuthorizationException::class);

    expect(($this->stored)($list->id)->status)->toBe(PriceListStatus::Draft)
        ->and(Fx::version($this->tenant))->toBe($version);
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'estoque e expedição' => [SystemRole::Warehouse],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('nega a gestão de tabelas no contexto de comprador', function (): void {
    $buyer = $this->tenant->addBuyerUser(Fx::company($this->tenant, 'Mercado Bom Preço'), SystemRole::BuyerAdmin);

    expect(fn () => ($this->save)($buyer, null, ($this->data)()))->toThrow(AuthorizationException::class);
});

it('trata a tabela de outro tenant como inexistente', function (): void {
    $other = TenantScenario::create();
    $foreign = Fx::priceList($other, 'PADRAO', activate: false);

    expect(fn () => ($this->save)($this->manager, $foreign, ($this->data)(['code' => 'INVADIDA'])))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->tenant->actingAs($this->manager, static fn () => app(ActivatePriceList::class)->handle($foreign)))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->tenant->actingAs($this->manager, static fn () => app(ArchivePriceList::class)->handle($foreign)))->toThrow(ModelNotFoundException::class);

    $stored = $other->system(static fn (): PriceList => PriceList::query()->findOrFail($foreign->id));

    expect($stored->code)->toBe('PADRAO')
        ->and($stored->status)->toBe(PriceListStatus::Draft);
});

it('ativa rascunhos uma única vez e incrementa a versão de preços', function (): void {
    $list = Fx::priceList($this->tenant, 'PADRAO', activate: false);
    $version = Fx::version($this->tenant);

    $this->tenant->actingAs($this->manager, static fn () => app(ActivatePriceList::class)->handle($list));
    $this->tenant->actingAs($this->manager, static fn () => app(ActivatePriceList::class)->handle($list));

    expect(($this->stored)($list->id)->status)->toBe(PriceListStatus::Active)
        ->and(Fx::version($this->tenant))->toBe($version + 1)
        ->and(Fx::auditActions($this->tenant, 'price_list.activated'))->toHaveCount(1);
});

it('não ativa tabela com vigência encerrada nem tabela arquivada', function (): void {
    $expired = Fx::priceList($this->tenant, 'OUTUBRO', from: '2026-10-01', until: '2026-10-31', activate: false);
    $archived = Fx::priceList($this->tenant, 'ANTIGA', activate: false);
    $this->tenant->actingAs($this->manager, static fn () => app(ArchivePriceList::class)->handle($archived));

    expect(fn () => $this->tenant->actingAs($this->manager, static fn () => app(ActivatePriceList::class)->handle($expired)))
        ->toThrow(InvalidPriceListTransition::class, 'A vigência desta tabela já terminou')
        ->and(fn () => $this->tenant->actingAs($this->manager, static fn () => app(ActivatePriceList::class)->handle($archived)))
        ->toThrow(InvalidPriceListTransition::class, 'Tabela arquivada não pode ser reativada');

    expect(($this->stored)($expired->id)->status)->toBe(PriceListStatus::Draft)
        ->and(($this->stored)($archived->id)->status)->toBe(PriceListStatus::Archived);
});

it('arquiva com motivo na auditoria, bloqueia novas alterações e é idempotente', function (): void {
    $list = Fx::priceList($this->tenant, 'PADRAO');
    $version = Fx::version($this->tenant);

    $this->tenant->actingAs($this->manager, static fn () => app(ArchivePriceList::class)->handle($list, 'Substituída pela tabela de 2027'));
    $this->tenant->actingAs($this->manager, static fn () => app(ArchivePriceList::class)->handle($list, 'Segunda tentativa'));

    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'price_list.archived')->sole());

    expect(($this->stored)($list->id)->status)->toBe(PriceListStatus::Archived)
        ->and($log->reason)->toBe('Substituída pela tabela de 2027')
        ->and($log->data['from'])->toBe('active')
        ->and(Fx::version($this->tenant))->toBe($version + 1);

    expect(fn () => ($this->save)($this->manager, $list, ($this->data)(['code' => 'PADRAO'])))
        ->toThrow(PriceListNotEditable::class);
});
