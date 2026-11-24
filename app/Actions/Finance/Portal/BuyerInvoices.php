<?php

declare(strict_types=1);

namespace App\Actions\Finance\Portal;

use App\Actions\Ordering\Portal\BuyerOrders;
use App\Data\Ordering\Portal\BuyerActor;
use App\Models\Finance\Invoice;
use App\Models\Ordering\Order;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Builder;

/**
 * Faturas visíveis ao comprador: as dos pedidos que ele vê (os que fez ou,
 * com portal.orders.view_all, todos da empresa).
 */
final readonly class BuyerInvoices
{
    public function __construct(private BuyerOrders $orders) {}

    public function actor(): BuyerActor
    {
        return $this->orders->actor();
    }

    /**
     * @return Builder<Invoice>
     */
    public function query(BuyerActor $actor): Builder
    {
        return Invoice::query()
            ->where('buyer_company_id', $actor->company->id)
            ->when(! $this->orders->seesAll(), static fn (Builder $query) => $query->whereIn('order_id', Order::query()->select('id')
                ->where('buyer_company_id', $actor->company->id)->where('placed_by_membership_id', $actor->membership->id)));
    }

    public function find(BuyerActor $actor, mixed $invoiceId): Invoice
    {
        return $this->query($actor)->whereKey(UntrustedId::uuid($invoiceId, Invoice::class))->firstOrFail();
    }
}
