<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\Exceptions\PriceListNotEditable;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Catalog\Sku;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Remove um SKU de uma tabela (as faixas do item são removidas em cascata).
 * Documentos já emitidos guardam o preço no próprio snapshot.
 */
final readonly class RemovePriceListItem
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $version,
    ) {}

    public function handle(PriceListItem $item): void
    {
        $this->access->authorize(Permission::PricingManage);

        DB::transaction(function () use ($item): void {
            // Ordem de locks igual à de SetPriceListItem: tabela, depois item.
            $listId = PriceListItem::query()->whereKey($item->id)->value('price_list_id');

            if (! is_string($listId)) {
                throw (new ModelNotFoundException)->setModel(PriceListItem::class, [$item->id]);
            }

            $list = PriceList::query()->lockForUpdate()->findOrFail($listId);

            if ($list->status === PriceListStatus::Archived) {
                throw new PriceListNotEditable('Tabela arquivada não aceita alterações de itens.');
            }

            $locked = PriceListItem::query()
                ->where('price_list_id', $list->id)
                ->lockForUpdate()
                ->findOrFail($item->id);

            $tiers = PriceTier::query()->where('price_list_item_id', $locked->id)->orderBy('min_quantity')->get();

            $data = [
                'price_list_id' => $list->id,
                'price_list_code' => $list->code,
                'sku_id' => $locked->sku_id,
                'sku_code' => Sku::query()->whereKey($locked->sku_id)->value('code'),
                'unit_price_cents' => $locked->unit_price_cents->cents,
                'tiers' => $tiers->map(static fn (PriceTier $tier): array => [
                    'min_quantity' => $tier->min_quantity->toDatabase(),
                    'unit_price_cents' => $tier->unit_price_cents->cents,
                ])->values()->all(),
            ];

            $locked->delete();

            $this->audit->record('price_list_item.removed', $locked, $data);
            $this->version->bump();
        });
    }
}
