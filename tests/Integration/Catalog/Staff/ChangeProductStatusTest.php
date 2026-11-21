<?php

declare(strict_types=1);

use App\Actions\Catalog\ChangeProductStatus;
use App\Domain\Catalog\Exceptions\InvalidProductStatusTransition;
use App\Domain\Catalog\Exceptions\ProductRequiresActiveSku;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->change = fn (TenantScenario $tenant, Membership $actor, Product $product, ProductStatus $status): Product => $tenant->actingAs(
        $actor,
        static fn (): Product => app(ChangeProductStatus::class)->handle($product, $status),
    );

    $this->stored = fn (TenantScenario $tenant, string $id): Product => $tenant->system(
        static fn (): Product => Product::query()->findOrFail($id),
    );

    $this->skuStatuses = fn (TenantScenario $tenant, Product $product): array => $tenant->system(
        static fn (): array => Sku::query()->where('product_id', $product->id)->orderBy('code')->get()
            ->mapWithKeys(static fn (Sku $sku): array => [$sku->code => $sku->status->value])
            ->all(),
    );
});

it('não ativa produto sem SKU ativo', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit);
    Fixtures::sku($tenant, $product, $unit, 'CAF-INATIVO', ['status' => SkuStatus::Inactive]);

    try {
        ($this->change)($tenant, $tenant->ownerMembership, $product, ProductStatus::Active);
        $this->fail('A ativação deveria exigir SKU ativo.');
    } catch (ProductRequiresActiveSku $e) {
        expect($e->errorCode())->toBe('product_requires_active_sku');
    }

    expect(($this->stored)($tenant, $product->id)->status)->toBe(ProductStatus::Draft)
        ->and(Fixtures::audits($tenant, 'product.status_changed'))->toBeEmpty();
});

it('ativa e inativa o produto com SKU ativo, registrando a auditoria', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit);
    Fixtures::sku($tenant, $product, $unit, 'CAF-1KG');
    $owner = $tenant->ownerMembership;

    ($this->change)($tenant, $owner, $product, ProductStatus::Active);
    expect(($this->stored)($tenant, $product->id)->status)->toBe(ProductStatus::Active);

    ($this->change)($tenant, $owner, $product, ProductStatus::Inactive);
    ($this->change)($tenant, $owner, $product, ProductStatus::Active);

    $logs = Fixtures::audits($tenant, 'product.status_changed');

    expect($logs->map(static fn ($log): array => $log->data)->all())->toEqual([
        ['from' => 'draft', 'to' => 'active'],
        ['from' => 'active', 'to' => 'inactive'],
        ['from' => 'inactive', 'to' => 'active'],
    ])->and($logs->first()->subject_id)->toBe($product->id);
});

it('arquiva o produto com data e arquiva todos os SKUs', function (): void {
    $tenant = TenantScenario::create();
    $clock = Fixtures::freezeClock('2026-11-17 15:30:00');
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit, ['status' => ProductStatus::Active]);
    Fixtures::sku($tenant, $product, $unit, 'LUVA-P');
    Fixtures::sku($tenant, $product, $unit, 'LUVA-M', ['status' => SkuStatus::Inactive]);
    Fixtures::sku($tenant, $product, $unit, 'LUVA-G', ['status' => SkuStatus::Archived]);

    ($this->change)($tenant, $tenant->ownerMembership, $product, ProductStatus::Archived);
    $stored = ($this->stored)($tenant, $product->id);

    expect($stored->status)->toBe(ProductStatus::Archived)
        ->and($stored->archived_at?->equalTo($clock->now()))->toBeTrue()
        ->and(($this->skuStatuses)($tenant, $product))->toBe(['LUVA-G' => 'archived', 'LUVA-M' => 'archived', 'LUVA-P' => 'archived'])
        ->and(Fixtures::audits($tenant, 'product.status_changed')->sole()->data)->toEqual([
            'from' => 'active',
            'to' => 'archived',
            'archived_skus' => ['LUVA-M', 'LUVA-P'],
        ]);
});

it('trata o arquivamento como definitivo e recusa voltar a rascunho', function (ProductStatus $from, ProductStatus $to): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit, [
        'status' => $from,
        'archived_at' => $from === ProductStatus::Archived ? now() : null,
    ]);
    Fixtures::sku($tenant, $product, $unit, 'SKU-TESTE');

    try {
        ($this->change)($tenant, $tenant->ownerMembership, $product, $to);
        $this->fail('Transição inválida deveria ser recusada.');
    } catch (InvalidProductStatusTransition $e) {
        expect($e->errorCode())->toBe('invalid_product_status_transition')
            ->and($e->details())->toBe(['from' => $from->value, 'to' => $to->value]);
    }

    expect(($this->stored)($tenant, $product->id)->status)->toBe($from);
})->with([
    'arquivado para ativo' => [ProductStatus::Archived, ProductStatus::Active],
    'arquivado para inativo' => [ProductStatus::Archived, ProductStatus::Inactive],
    'ativo para rascunho' => [ProductStatus::Active, ProductStatus::Draft],
    'inativo para rascunho' => [ProductStatus::Inactive, ProductStatus::Draft],
]);

it('não registra nada quando a situação já é a solicitada', function (): void {
    $tenant = TenantScenario::create();
    $product = Fixtures::product($tenant, Fixtures::unit($tenant, 'UN'));

    ($this->change)($tenant, $tenant->ownerMembership, $product, ProductStatus::Draft);

    expect(Fixtures::audits($tenant, 'product.status_changed'))->toBeEmpty();
});

it('exige catalog.manage', function (SystemRole $role): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit);
    Fixtures::sku($tenant, $product, $unit, 'CAF-1KG');

    expect(fn () => ($this->change)($tenant, $tenant->addStaff($role), $product, ProductStatus::Active))
        ->toThrow(AuthorizationException::class);

    expect(($this->stored)($tenant, $product->id)->status)->toBe(ProductStatus::Draft);
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'estoque' => [SystemRole::Warehouse],
]);

it('não altera produto de outro tenant', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $unit = Fixtures::unit($other, 'UN');
    $foreign = Fixtures::product($other, $unit, ['status' => ProductStatus::Active]);
    Fixtures::sku($other, $foreign, $unit, 'CAF-1KG');

    expect(fn () => ($this->change)($tenant, $tenant->ownerMembership, $foreign, ProductStatus::Archived))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)($other, $foreign->id)->status)->toBe(ProductStatus::Active);
});
