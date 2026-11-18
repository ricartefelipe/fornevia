<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Data\Ordering\CartLineQuote;
use App\Data\Ordering\CartQuote;
use App\Domain\Catalog\PurchaseRuleViolation;
use App\Domain\Ordering\DocumentTotals;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Precificação do carrinho no servidor: a mesma lógica alimenta a tela e
 * a confirmação do pedido. Preços nunca vêm do cliente.
 *
 * Disponibilidade é apenas informativa ("disponível", "sob encomenda",
 * "indisponível"): a reserva só acontece na confirmação, sob lock.
 */
final readonly class CartPricing
{
    public function __construct(private PricingService $pricing) {}

    public function quote(Cart $cart, BuyerCompany $buyer): CartQuote
    {
        /** @var list<CartItem> $items */
        $items = $cart->items()->with(['sku.product', 'sku.salesUnit'])->orderBy('created_at')->get()->all();

        if ($items === []) {
            return new CartQuote([], DocumentTotals::of([]));
        }

        $prices = $this->pricing->resolve($buyer, array_column(array_map(
            static fn (CartItem $item): array => [$item->sku_id, $item->quantity],
            $items,
        ), 1, 0));

        $available = $this->availableBySku(array_map(static fn (CartItem $i): string => $i->sku_id, $items));
        $lines = [];
        $amounts = [];

        foreach ($items as $item) {
            $line = $this->line($item, $item->sku, $prices[$item->sku_id] ?? null, $available[$item->sku_id] ?? Quantity::zero());
            $lines[] = $line;

            if ($line->amounts !== null) {
                $amounts[] = $line->amounts;
            }
        }

        return new CartQuote($lines, DocumentTotals::of($amounts));
    }

    private function line(CartItem $item, ?Sku $sku, ?\App\Domain\Pricing\ResolvedPrice $price, Quantity $available): CartLineQuote
    {
        if ($sku === null) {
            // SKU não visível (restrito ou arquivado) para esta empresa.
            return new CartLineQuote($item->id, $item->sku_id, '—', 'Item indisponível', null, '', null, null, $item->quantity, null, [], null, null, 'unavailable', false, ['O item não está mais disponível para a sua empresa.']);
        }

        $problems = [];

        if (! $sku->isSellable()) {
            $problems[] = 'Item fora de linha ou inativo.';
        }

        $violations = $sku->purchaseRule()->violations($item->quantity);

        foreach ($violations as $violation) {
            $problems[] = match ($violation) {
                PurchaseRuleViolation::NotPositive => 'Quantidade deve ser positiva.',
                PurchaseRuleViolation::TooManyDecimals => 'Quantidade com casas decimais não aceitas pela unidade.',
                PurchaseRuleViolation::BelowMinimum => 'Abaixo da quantidade mínima de '.$sku->min_order_quantity->format().' '.$sku->salesUnit->code.'.',
                PurchaseRuleViolation::NotMultiple => 'Quantidade deve ser múltipla de '.$sku->order_multiple->format().' '.$sku->salesUnit->code.'.',
            };
        }

        try {
            $base = $sku->toBaseQuantity($item->quantity);
        } catch (InvalidArgumentException) {
            $base = null;
            $problems[] = 'Conversão de unidade inválida para esta quantidade.';
        }

        if ($price === null) {
            $problems[] = 'Item sem preço vigente para a sua empresa.';
        }

        try {
            $amounts = $price !== null ? LineAmounts::calculate($price->unitPrice, Percentage::zero(), $item->quantity) : null;
        } catch (AmountOutOfRange) {
            $amounts = null;
            $problems[] = 'O total do item excede o limite de cálculo. Reduza a quantidade.';
        }
        $availability = match (true) {
            $base !== null && $available->greaterThanOrEqual($base) => 'available',
            $sku->product->allow_backorder => 'backorder',
            default => 'unavailable',
        };

        if ($availability === 'unavailable') {
            $problems[] = 'Estoque insuficiente no momento.';
        }

        return new CartLineQuote(
            $item->id, $sku->id, $sku->code, $sku->product->name, $sku->variant_label, $sku->salesUnit->code, $sku->package_label,
            $sku->conversion_factor, $item->quantity, $base, $violations, $price, $amounts, $availability, $sku->product->allow_backorder, $problems,
        );
    }

    /**
     * Disponível (físico − reservado) somado nos depósitos que atendem vendas.
     *
     * @param  list<string>  $skuIds
     * @return array<string, Quantity>
     */
    private function availableBySku(array $skuIds): array
    {
        $rows = DB::table('stock_levels as sl')
            ->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->where('w.is_active', true)
            ->where('w.allows_sales', true)
            ->whereIn('sl.sku_id', $skuIds)
            ->groupBy('sl.sku_id')
            ->selectRaw('sl.sku_id, sum(sl.on_hand - sl.reserved) as available')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->sku_id] = Quantity::of((string) $row->available);
        }

        return $result;
    }
}
