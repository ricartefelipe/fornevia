<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Staff;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Models\Ordering\OrderItem;

/**
 * Custo histórico do item (snapshot de order_item_costs na confirmação) e
 * margem resultante. Dado interno: só é montado para quem tem
 * catalog.view_costs.
 *
 * Margem = (preço líquido − custo) ÷ preço líquido, como na política de
 * descontos; a margem da linha considera a quantidade pedida.
 */
final readonly class StaffItemCost
{
    public function __construct(
        public ?Money $unitCost,
        public ?Percentage $margin,
        public ?Money $lineMargin,
    ) {}

    public static function for(OrderItem $item, ?Money $unitCost): self
    {
        if ($unitCost === null) {
            return new self(null, null, null);
        }

        $net = $item->unit_net_price_cents;

        return new self(
            $unitCost,
            $net->ratioOf($net->minus($unitCost)),
            $item->line_total_cents->minus($unitCost->times($item->quantity)),
        );
    }
}
