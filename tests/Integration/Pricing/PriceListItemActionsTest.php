<?php

declare(strict_types=1);

use App\Actions\Pricing\ArchivePriceList;
use App\Actions\Pricing\RemovePriceListItem;
use App\Actions\Pricing\SetPriceListItem;
use App\Domain\Pricing\Exceptions\InvalidPriceTiers;
use App\Domain\Pricing\Exceptions\InvalidUnitPrice;
use App\Domain\Pricing\Exceptions\PriceListNotEditable;
use App\Domain\Pricing\Exceptions\SkuNotPriceable;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Catalog\Sku;
use App\Models\Identity\Membership;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->list = Fx::priceList($this->tenant, 'PADRAO');
    $this->sku = Fx::sku($this->tenant, 'OLE-900', 'Óleo de soja 900 ml');

    $this->set = fn (Membership $actor, PriceList $list, Sku $sku, string $price, array $tiers = []): PriceListItem => $this->tenant->actingAs(
        $actor,
        static fn (): PriceListItem => app(SetPriceListItem::class)->handle(
            $list,
            $sku,
            Money::parse($price),
            array_map(static fn (array $t): array => [Quantity::of($t[0]), Money::parse($t[1])], $tiers),
        ),
    );

    $this->stored = fn (): array => $this->tenant->system(fn (): array => PriceListItem::query()
        ->with('tiers')
        ->where('price_list_id', $this->list->id)
        ->get()
        ->mapWithKeys(static fn (PriceListItem $item): array => [$item->sku_id => [
            'price' => $item->unit_price_cents->cents,
            'tiers' => $item->tiers->map(static fn (PriceTier $t): array => [$t->min_quantity->toDatabase(), $t->unit_price_cents->cents])->all(),
        ]])
        ->all());
});

it('define preço e faixas do SKU, com auditoria e nova versão de preços', function (): void {
    $version = Fx::version($this->tenant);

    $item = ($this->set)($this->manager, $this->list, $this->sku, '8,99', [['12', '8,49'], ['60', '7,99']]);

    expect(($this->stored)())->toBe([$this->sku->id => [
        'price' => 899,
        'tiers' => [['12.000', 849], ['60.000', 799]],
    ]])
        ->and($item->tiers)->toHaveCount(2)
        ->and(Fx::version($this->tenant))->toBe($version + 1);

    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'price_list_item.created')->sole());

    expect($log->subject_type)->toBe('price_list_item')
        ->and($log->data['sku_code'])->toBe('OLE-900')
        ->and($log->data['after'])->toEqual([
            'unit_price_cents' => 899,
            'tiers' => [
                ['min_quantity' => '12.000', 'unit_price_cents' => 849],
                ['min_quantity' => '60.000', 'unit_price_cents' => 799],
            ],
        ]);
});

it('substitui o preço e todas as faixas ao gravar novamente o mesmo SKU', function (): void {
    ($this->set)($this->manager, $this->list, $this->sku, '8,99', [['12', '8,49'], ['60', '7,99']]);
    ($this->set)($this->manager, $this->list, $this->sku, '9,29', [['24', '8,79']]);

    expect(($this->stored)())->toBe([$this->sku->id => ['price' => 929, 'tiers' => [['24.000', 879]]]]);

    ($this->set)($this->manager, $this->list, $this->sku, '9,49');

    $log = $this->tenant->system(static fn () => AuditLog::query()->where('action', 'price_list_item.updated')->orderBy('created_at')->orderBy('id')->get()->last());

    expect(($this->stored)())->toBe([$this->sku->id => ['price' => 949, 'tiers' => []]])
        ->and($this->tenant->system(static fn (): int => PriceListItem::query()->count()))->toBe(1)
        ->and($log->data['before'])->toEqual(['unit_price_cents' => 929, 'tiers' => [['min_quantity' => '24.000', 'unit_price_cents' => 879]]]);
});

it('recusa preço não positivo e faixas inválidas sem gravar nada', function (string $price, array $tiers, string $exception, string $message): void {
    $version = Fx::version($this->tenant);

    expect(fn () => ($this->set)($this->manager, $this->list, $this->sku, $price, $tiers))->toThrow($exception, $message);

    expect(($this->stored)())->toBe([])
        ->and(Fx::version($this->tenant))->toBe($version);
})->with([
    'preço zero' => ['0,00', [], InvalidUnitPrice::class, 'O preço unitário deve ser maior que zero.'],
    'preço negativo' => ['-1,00', [], InvalidUnitPrice::class, 'O preço unitário deve ser maior que zero.'],
    'mínimo zero' => ['9,00', [['0', '8,00']], InvalidPriceTiers::class, 'A quantidade mínima da faixa 1 deve ser maior que zero.'],
    'mínimos repetidos' => ['9,00', [['10', '8,50'], ['10', '8,00']], InvalidPriceTiers::class, 'A quantidade mínima da faixa 2 deve ser maior que a da faixa anterior.'],
    'mínimos decrescentes' => ['9,00', [['20', '8,50'], ['10', '8,00']], InvalidPriceTiers::class, 'A quantidade mínima da faixa 2 deve ser maior que a da faixa anterior.'],
    'preço de faixa zero' => ['9,00', [['10', '0,00']], InvalidPriceTiers::class, 'O preço da faixa 1 deve ser maior que zero.'],
]);

it('limita a quantidade de faixas por item', function (): void {
    $tiers = array_map(static fn (int $n): array => [(string) ($n * 10), '5,00'], range(1, SetPriceListItem::MAX_TIERS + 1));

    expect(fn () => ($this->set)($this->manager, $this->list, $this->sku, '9,00', $tiers))
        ->toThrow(InvalidPriceTiers::class, 'Informe no máximo 20 faixas por item.');
});

it('indica a faixa inválida nos detalhes da violação', function (): void {
    try {
        ($this->set)($this->manager, $this->list, $this->sku, '9,00', [['10', '8,50'], ['5', '8,00']]);
        $this->fail('As faixas não deveriam ter sido aceitas.');
    } catch (InvalidPriceTiers $e) {
        expect($e->errorCode())->toBe('invalid_price_tiers')
            ->and($e->details())->toBe(['field' => 'tiers', 'index' => 1, 'attribute' => 'min_quantity']);
    }
});

it('não altera itens de tabela arquivada nem precifica SKU arquivado', function (): void {
    $archivedSku = Fx::sku($this->tenant, 'OLE-LEGADO', 'Óleo de soja 1 L (descontinuado)', status: SkuStatus::Archived);
    $item = ($this->set)($this->manager, $this->list, $this->sku, '8,99');

    expect(fn () => ($this->set)($this->manager, $this->list, $archivedSku, '8,99'))->toThrow(SkuNotPriceable::class);

    $this->tenant->actingAs($this->manager, fn () => app(ArchivePriceList::class)->handle($this->list));

    expect(fn () => ($this->set)($this->manager, $this->list, $this->sku, '7,99'))->toThrow(PriceListNotEditable::class)
        ->and(fn () => $this->tenant->actingAs($this->manager, static fn () => app(RemovePriceListItem::class)->handle($item)))
        ->toThrow(PriceListNotEditable::class);

    expect(($this->stored)())->toBe([$this->sku->id => ['price' => 899, 'tiers' => []]]);
});

it('remove o item com as faixas, registra os valores na auditoria e incrementa a versão', function (): void {
    $item = ($this->set)($this->manager, $this->list, $this->sku, '8,99', [['12', '8,49']]);
    $version = Fx::version($this->tenant);

    $this->tenant->actingAs($this->manager, static fn () => app(RemovePriceListItem::class)->handle($item));

    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'price_list_item.removed')->sole());

    expect(($this->stored)())->toBe([])
        ->and($this->tenant->system(static fn (): int => PriceTier::query()->count()))->toBe(0)
        ->and($log->data['sku_code'])->toBe('OLE-900')
        ->and($log->data['unit_price_cents'])->toBe(899)
        ->and($log->data['tiers'])->toBe([['min_quantity' => '12.000', 'unit_price_cents' => 849]])
        ->and(Fx::version($this->tenant))->toBe($version + 1);
});

it('exige pricing.manage para definir e remover itens', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $item = ($this->set)($this->manager, $this->list, $this->sku, '8,99');

    expect(fn () => ($this->set)($actor, $this->list, $this->sku, '1,00'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tenant->actingAs($actor, static fn () => app(RemovePriceListItem::class)->handle($item)))
        ->toThrow(AuthorizationException::class);

    expect(($this->stored)())->toBe([$this->sku->id => ['price' => 899, 'tiers' => []]]);
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'consulta' => [SystemRole::Viewer],
    'estoque e expedição' => [SystemRole::Warehouse],
]);

it('trata tabela, SKU e item de outro tenant como inexistentes', function (): void {
    $other = TenantScenario::create();
    $foreignList = Fx::priceList($other, 'PADRAO');
    $foreignSku = Fx::sku($other, 'OLE-900', 'Óleo de soja 900 ml');
    $foreignItem = Fx::item($other, $foreignList, $foreignSku, '7,50');

    expect(fn () => ($this->set)($this->manager, $foreignList, $this->sku, '1,00'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->set)($this->manager, $this->list, $foreignSku, '1,00'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->tenant->actingAs($this->manager, static fn () => app(RemovePriceListItem::class)->handle($foreignItem)))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)())->toBe([])
        ->and($other->system(static fn (): int => PriceListItem::query()->findOrFail($foreignItem->id)->unit_price_cents->cents))->toBe(750);
});
