<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\Exceptions\InvalidPriceTiers;
use App\Domain\Pricing\Exceptions\InvalidUnitPrice;
use App\Domain\Pricing\Exceptions\PriceListNotEditable;
use App\Domain\Pricing\Exceptions\SkuNotPriceable;
use App\Domain\Pricing\Exceptions\SkuPackagingChanged;
use App\Domain\Pricing\PriceTier as Tier;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Define o preço de um SKU em uma tabela e substitui as faixas por volume
 * do item (ADR-010: a faixa atingida vale para todas as unidades).
 *
 * Faixas: quantidades mínimas positivas, estritamente crescentes, na ordem
 * informada e com as casas decimais da unidade de venda; preços positivos.
 * A tabela é bloqueada antes do item, o que serializa gravações
 * concorrentes do mesmo SKU. SKU e unidade de venda são lidos com lock
 * compartilhado (serializa com a troca de embalagem e a redução de casas).
 *
 * $expectedPackaging (Sku::packagingFingerprint() de quando o formulário
 * foi aberto) recusa a gravação se a embalagem mudou nesse intervalo.
 */
final readonly class SetPriceListItem
{
    public const int MAX_TIERS = 20;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $version,
    ) {}

    /**
     * @param  array<int, array{0: Quantity, 1: Money}>  $tiers  [quantidade mínima, preço unitário]
     */
    public function handle(PriceList $priceList, Sku $sku, Money $unitPrice, array $tiers = [], ?string $expectedPackaging = null): PriceListItem
    {
        $this->access->authorize(Permission::PricingManage);

        if (! $unitPrice->isPositive()) {
            throw new InvalidUnitPrice('O preço unitário deve ser maior que zero.', ['field' => 'unit_price']);
        }

        $normalized = self::tiers($tiers);

        return DB::transaction(function () use ($priceList, $sku, $unitPrice, $normalized, $expectedPackaging): PriceListItem {
            $list = PriceList::query()->lockForUpdate()->findOrFail($priceList->id);

            if ($list->status === PriceListStatus::Archived) {
                throw new PriceListNotEditable('Tabela arquivada não aceita alterações de itens.');
            }

            // Compartilhado: serializa com a troca de embalagem em SaveSku.
            $sku = Sku::query()->sharedLock()->findOrFail($sku->id);

            if ($sku->status === SkuStatus::Archived) {
                throw new SkuNotPriceable("O SKU {$sku->code} está arquivado e não pode receber preço.", ['field' => 'sku']);
            }

            $unit = UnitOfMeasure::query()->sharedLock()->findOrFail($sku->sales_unit_id);

            if ($expectedPackaging !== null && $expectedPackaging !== $sku->packagingFingerprint()) {
                throw SkuPackagingChanged::forSku($sku->code, $unit->code);
            }

            foreach ($normalized as $index => $tier) {
                if ($tier->minQuantity->decimalPlaces() > $unit->decimals) {
                    throw new InvalidPriceTiers(
                        'A quantidade mínima da faixa '.($index + 1).($unit->decimals === 0
                            ? " deve ser inteira (unidade {$unit->code})."
                            : " aceita até {$unit->decimals} casas decimais (unidade {$unit->code})."),
                        ['field' => 'tiers', 'index' => $index, 'attribute' => 'min_quantity'],
                    );
                }
            }

            $item = PriceListItem::query()
                ->with('tiers')
                ->where('price_list_id', $list->id)
                ->where('sku_id', $sku->id)
                ->lockForUpdate()
                ->first();

            $before = $item !== null ? self::snapshot($item->unit_price_cents, self::fromModels($item)) : null;

            if ($item === null) {
                $item = new PriceListItem;
                $item->forceFill(['price_list_id' => $list->id, 'sku_id' => $sku->id]);
            }

            $item->forceFill(['unit_price_cents' => $unitPrice])->save();

            PriceTier::query()->where('price_list_item_id', $item->id)->delete();

            foreach ($normalized as $tier) {
                (new PriceTier)->forceFill([
                    'price_list_item_id' => $item->id,
                    'min_quantity' => $tier->minQuantity,
                    'unit_price_cents' => $tier->unitPrice,
                ])->save();
            }

            $this->audit->record($before === null ? 'price_list_item.created' : 'price_list_item.updated', $item, [
                'price_list_id' => $list->id,
                'price_list_code' => $list->code,
                'sku_id' => $sku->id,
                'sku_code' => $sku->code,
                'before' => $before,
                'after' => self::snapshot($unitPrice, $normalized),
            ]);
            $this->version->bump();

            return $item->load('tiers');
        });
    }

    /**
     * @param  array<int, array{0: Quantity, 1: Money}>  $tiers
     * @return list<Tier>
     */
    private static function tiers(array $tiers): array
    {
        if (count($tiers) > self::MAX_TIERS) {
            throw new InvalidPriceTiers('Informe no máximo '.self::MAX_TIERS.' faixas por item.', ['field' => 'tiers']);
        }

        $normalized = [];
        $previous = null;

        foreach (array_values($tiers) as $index => [$minimum, $price]) {
            $position = $index + 1;

            if (! $minimum->isPositive()) {
                throw new InvalidPriceTiers(
                    "A quantidade mínima da faixa {$position} deve ser maior que zero.",
                    ['field' => 'tiers', 'index' => $index, 'attribute' => 'min_quantity'],
                );
            }

            if ($previous !== null && ! $minimum->greaterThan($previous)) {
                throw new InvalidPriceTiers(
                    "A quantidade mínima da faixa {$position} deve ser maior que a da faixa anterior.",
                    ['field' => 'tiers', 'index' => $index, 'attribute' => 'min_quantity'],
                );
            }

            if (! $price->isPositive()) {
                throw new InvalidPriceTiers(
                    "O preço da faixa {$position} deve ser maior que zero.",
                    ['field' => 'tiers', 'index' => $index, 'attribute' => 'unit_price'],
                );
            }

            $normalized[] = new Tier($minimum, $price);
            $previous = $minimum;
        }

        return $normalized;
    }

    /**
     * @return list<Tier>
     */
    private static function fromModels(PriceListItem $item): array
    {
        return array_values($item->tiers->map(
            static fn (PriceTier $tier): Tier => new Tier($tier->min_quantity, $tier->unit_price_cents),
        )->all());
    }

    /**
     * @param  list<Tier>  $tiers
     * @return array{unit_price_cents: int, tiers: list<array{min_quantity: string, unit_price_cents: int}>}
     */
    private static function snapshot(Money $unitPrice, array $tiers): array
    {
        return [
            'unit_price_cents' => $unitPrice->cents,
            'tiers' => array_map(static fn (Tier $tier): array => [
                'min_quantity' => $tier->minQuantity->toDatabase(),
                'unit_price_cents' => $tier->unitPrice->cents,
            ], $tiers),
        ];
    }
}
