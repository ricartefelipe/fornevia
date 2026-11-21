<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Support\CartWriter;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Actions\Ordering\Portal\Support\PurchasableSkus;
use App\Data\Ordering\Portal\CartAdditionReport;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Enums\Identity\Permission;
use App\Models\Ordering\OrderItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Recompra (RF-060): adiciona ao carrinho os itens de um pedido visível ao
 * comprador, nas quantidades pedidas originalmente. Nada é copiado do
 * pedido além do SKU e da quantidade: preço e disponibilidade são os
 * vigentes (o carrinho é recalculado no servidor). Itens que deixaram de
 * estar disponíveis são informados; quantidades fora da regra de compra
 * atual são ajustadas para a válida mais próxima.
 */
final readonly class Reorder
{
    public function __construct(
        private PortalBuyer $buyer,
        private BuyerOrders $orders,
        private PurchasableSkus $skus,
        private CartWriter $writer,
    ) {}

    /**
     * @throws ModelNotFoundException pedido não visível ao comprador
     * @throws InvalidCartItem total do carrinho acima do limite de cálculo
     */
    public function handle(string $orderId): CartAdditionReport
    {
        $actor = $this->buyer->require(Permission::PortalCart);
        $order = $this->orders->find($actor, $orderId);

        return DB::transaction(function () use ($actor, $order): CartAdditionReport {
            $cart = $this->writer->lockOpenCart($actor) ?? throw InvalidCartItem::because('Carrinho indisponível.', null, 'cart');

            /** @var list<OrderItem> $items */
            $items = $order->items->all();
            $skus = $this->skus->byIds($actor->company, array_map(static fn (OrderItem $item): string => $item->sku_id, $items));
            $lines = [];

            foreach ($items as $item) {
                $lines[] = $this->writer->addFromSource(
                    $cart, $actor->company, $skus[$item->sku_id] ?? null, $item->quantity, $item->sku_code, $item->sku_code, $item->product_name,
                );
            }

            return $this->writer->report($cart, $actor->company, $lines);
        });
    }
}
