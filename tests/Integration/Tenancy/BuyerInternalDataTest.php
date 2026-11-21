<?php

declare(strict_types=1);

use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductVisibility;
use App\Models\Audit\AuditLog;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Integration\Pricing\PricingFixtures as Pricing;
use Tests\Support\Builders\TenantScenario;

/*
| Defesa em profundidade no contexto de comprador: o banco limita o que a
| conexão do portal lê e altera em dados internos ou de outras empresas,
| mesmo com SQL direto e sem os filtros da aplicação.
*/

beforeEach(function (): void {
    Pricing::freezeClock();
    $this->tenant = TenantScenario::create(name: 'Distribuidora Serra Azul');
    $this->hospitals = Catalog::group($this->tenant, 'HOSP', 'Hospitais e clínicas');
    $this->retail = Catalog::group($this->tenant, 'REDE', 'Rede varejista');
    $this->clinic = $this->tenant->addBuyerCompany(['trade_name' => 'Clínica Sol', 'customer_group_id' => $this->hospitals->id]);
    $this->market = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço', 'customer_group_id' => $this->retail->id]);
    $this->buyer = $this->tenant->addBuyerUser($this->clinic);

    $restricted = Catalog::simpleProduct($this->tenant, 'Kit premium', 'KIT-PREMIUM', ['visibility' => ProductVisibility::Restricted]);
    $hospital = Catalog::simpleProduct($this->tenant, 'Luva cirúrgica', 'LUV-CIR', ['visibility' => ProductVisibility::Restricted]);
    $own = Catalog::simpleProduct($this->tenant, 'Máscara N95', 'MASC-N95', ['visibility' => ProductVisibility::Restricted]);

    $this->retailCatalog = Catalog::privateCatalog($this->tenant, 'REDE-PREMIUM', [$restricted]);
    Catalog::assign($this->tenant, $this->retailCatalog, $this->retail);
    $this->groupCatalog = Catalog::privateCatalog($this->tenant, 'HOSP-LINHA', [$hospital]);
    Catalog::assign($this->tenant, $this->groupCatalog, $this->hospitals);
    $this->companyCatalog = Catalog::privateCatalog($this->tenant, 'SOL-EXCLUSIVO', [$own]);
    Catalog::assign($this->tenant, $this->companyCatalog, $this->clinic);
    $this->unassigned = Catalog::privateCatalog($this->tenant, 'RASCUNHO', [$restricted, $hospital]);

    $this->asBuyer = fn (Closure $callback): mixed => $this->tenant->actingAs($this->buyer, $callback);
});

it('mostra ao comprador apenas os catálogos privados atribuídos à empresa ou ao grupo', function (): void {
    $visible = ($this->asBuyer)(static fn (): array => [
        'catalogs' => DB::table('private_catalogs')->orderBy('code')->pluck('code')->all(),
        'products' => DB::table('private_catalog_products')->distinct()->pluck('private_catalog_id')->sort()->values()->all(),
        'assignments' => DB::table('private_catalog_assignments')->pluck('private_catalog_id')->sort()->values()->all(),
    ]);
    $expectedIds = collect([$this->groupCatalog->id, $this->companyCatalog->id])->sort()->values()->all();

    expect($visible['catalogs'])->toBe(['HOSP-LINHA', 'SOL-EXCLUSIVO'])
        ->and($visible['products'])->toBe($expectedIds)
        ->and($visible['assignments'])->toBe($expectedIds);

    $staff = $this->tenant->system(static fn (): int => DB::table('private_catalogs')->count());
    expect($staff)->toBe(4);
});

it('mantém a visibilidade de produtos do catálogo atribuído ao comprador', function (): void {
    $names = ($this->asBuyer)(static fn (): array => DB::table('products')->orderBy('name')->pluck('name')->all());

    expect($names)->toBe(['Luva cirúrgica', 'Máscara N95']);
});

it('impede o comprador de alterar a versão de preços e de ler a margem mínima interna', function (): void {
    $before = $this->tenant->system(static fn (): int => (int) DB::table('pricing_settings')->value('price_version'));

    $changed = ($this->asBuyer)(static fn (): array => [
        DB::table('pricing_settings')->update(['price_version' => 999]),
        DB::table('pricing_settings')->delete(),
        DB::table('pricing_policies')->count(),
        DB::table('pricing_policies')->update(['min_margin_bps' => 0]),
    ]);

    expect($changed)->toBe([0, 0, 0, 0])
        ->and($this->tenant->system(static fn (): int => (int) DB::table('pricing_settings')->value('price_version')))->toBe($before)
        ->and($this->tenant->system(static fn (): int => (int) DB::table('pricing_policies')->value('min_margin_bps')))->toBe(1_000)
        ->and(($this->asBuyer)(static fn (): int => DB::table('pricing_settings')->count()))->toBe(1);
});

it('não mostra ao comprador a auditoria do tenant, nem as ações que ele registrou como equipe', function (): void {
    $this->tenant->actingAs($this->tenant->ownerMembership, fn (): AuditLog => app(AuditRecorder::class)->record(
        'negotiated_price.created', null, ['buyer_company_id' => $this->market->id, 'unit_price_cents' => 1890],
    ));
    ($this->asBuyer)(static fn (): AuditLog => app(AuditRecorder::class)->record('portal.list_shared', null, ['name' => 'Reposição semanal']));

    // O mesmo usuário com associação de equipe e de comprador.
    $user = App\Models\Identity\User::query()->findOrFail($this->buyer->user_id);
    $staff = $this->tenant->addStaff(App\Enums\Identity\SystemRole::SalesRep, $user);
    $this->tenant->actingAs($staff, fn (): AuditLog => app(AuditRecorder::class)->record(
        'negotiated_price.created', null, ['buyer_company_id' => $this->market->id, 'unit_price_cents' => 1750],
    ));

    $buyerView = ($this->asBuyer)(static fn (): int => DB::table('audit_logs')->count());
    $staffView = $this->tenant->system(static fn (): array => DB::table('audit_logs')->orderBy('created_at')->pluck('action')->all());

    expect($buyerView)->toBe(0)
        ->and($staffView)->toContain('negotiated_price.created', 'portal.list_shared');
});

it('impede o comprador de gravar auditoria em nome de outro usuário ou de outra empresa', function (): void {
    $insert = fn (array $row): Closure => fn () => ($this->asBuyer)(fn () => DB::table('audit_logs')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'tenant_id' => $this->tenant->organization->id,
        'action' => 'buyer_company.updated',
        'data' => '{}',
        ...$row,
    ]));

    expect($insert(['actor_user_id' => $this->tenant->ownerMembership->user_id]))->toThrow(Illuminate\Database\QueryException::class)
        ->and($insert(['actor_user_id' => $this->buyer->user_id, 'data' => json_encode(['buyer_company_id' => $this->market->id])]))
        ->toThrow(Illuminate\Database\QueryException::class);

    ($insert(['actor_user_id' => $this->buyer->user_id, 'data' => json_encode(['buyer_company_id' => $this->clinic->id])]))();

    expect($this->tenant->system(static fn (): int => DB::table('audit_logs')->where('action', 'buyer_company.updated')->count()))->toBe(1);
});

it('impede o comprador de alterar o cadastro da própria empresa pelo banco', function (): void {
    $this->tenant->system(fn () => DB::table('buyer_contacts')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'tenant_id' => $this->tenant->organization->id,
        'buyer_company_id' => $this->clinic->id,
        'name' => 'Patrícia Nogueira',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $changed = ($this->asBuyer)(static fn (): array => [
        DB::table('buyer_contacts')->count(),
        DB::table('buyer_contacts')->update(['name' => 'Outro nome']),
        DB::table('buyer_contacts')->delete(),
        DB::table('buyer_units')->update(['name' => 'Outro nome']),
        DB::table('buyer_addresses')->update(['street' => 'Outra rua']),
        DB::table('cost_centers')->update(['name' => 'Outro nome']),
    ]);

    expect($changed)->toBe([1, 0, 0, 0, 0, 0]);
});

it('oculta preços de produtos restritos fora do catálogo do comprador', function (): void {
    $restricted = $this->tenant->system(static fn () => App\Models\Catalog\Sku::query()->where('code', 'KIT-PREMIUM')->firstOrFail());
    $visible = $this->tenant->system(static fn () => App\Models\Catalog\Sku::query()->where('code', 'LUV-CIR')->firstOrFail());
    $list = Pricing::priceList($this->tenant, 'PADRAO');
    Pricing::item($this->tenant, $list, $restricted, '99,00', [['10', '89,00']]);
    Pricing::item($this->tenant, $list, $visible, '12,00', [['10', '11,00']]);
    Pricing::negotiated($this->tenant, $this->clinic, $restricted, '80,00', '2026-11-01');

    $seen = ($this->asBuyer)(static fn (): array => [
        'items' => DB::table('price_list_items')->pluck('sku_id')->all(),
        'tiers' => DB::table('price_tiers')->count(),
        'negotiated' => DB::table('negotiated_prices')->count(),
    ]);

    expect($seen)->toBe(['items' => [$visible->id], 'tiers' => 1, 'negotiated' => 0]);
});

it('recusa calcular no contexto de comprador o preço de outra empresa', function (): void {
    $sku = Pricing::sku($this->tenant, 'CAF-1KG', 'Café torrado 1 kg');

    expect(fn () => ($this->asBuyer)(fn (): array => app(PricingService::class)->resolve($this->market, [$sku->id => Quantity::of(1)])))
        ->toThrow(LogicException::class);

    $own = ($this->asBuyer)(fn (): array => app(PricingService::class)->resolve($this->clinic, [$sku->id => Quantity::of(1)]));
    expect($own)->toBe([$sku->id => null]);
});

it('indexa a auditoria pela empresa compradora em coluna gerada usável sob RLS', function (): void {
    $this->tenant->actingAs($this->tenant->ownerMembership, fn (): AuditLog => app(AuditRecorder::class)->record(
        'buyer_contact.created', null, ['buyer_company_id' => $this->clinic->id],
    ));
    $this->tenant->actingAs($this->tenant->ownerMembership, static fn (): AuditLog => app(AuditRecorder::class)->record(
        'pricing_settings.updated', null, ['buyer_company_id' => 'nao-e-uuid'],
    ));

    $stored = $this->tenant->system(static fn (): array => DB::table('audit_logs')->whereIn('action', ['buyer_contact.created', 'pricing_settings.updated'])->orderBy('action')->pluck('buyer_company_id', 'action')->all());
    $definition = DB::selectOne("select indexdef from pg_indexes where tablename = 'audit_logs' and indexname = 'audit_logs_buyer_company_index'");

    expect($stored)->toBe(['buyer_contact.created' => $this->clinic->id, 'pricing_settings.updated' => null])
        ->and($definition?->indexdef)->toContain('(tenant_id, buyer_company_id, created_at)');

    // Sob RLS (contexto de equipe), o filtro pela coluna gerada pode usar o
    // índice (comparação uuid leakproof); com varredura sequencial
    // desestimulada, o plano precisa escolhê-lo.
    $plan = $this->tenant->actingAs($this->tenant->ownerMembership, function (): string {
        DB::statement('set local enable_seqscan = off');

        return implode("\n", array_map(
            static fn (object $row): string => (string) $row->{'QUERY PLAN'},
            DB::select('explain select id from audit_logs where buyer_company_id = ?', [$this->clinic->id]),
        ));
    });

    expect($plan)->toContain('audit_logs_buyer_company_index');
});
