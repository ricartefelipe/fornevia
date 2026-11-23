<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Portal;

use App\Actions\Ordering\Portal\BuyerOrders;
use App\Data\Ordering\Portal\BuyerActor;
use App\Models\Fulfillment\ReturnRequest;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Builder;

/**
 * Devoluções visíveis ao comprador: as dos pedidos que ele vê (os
 * próprios ou, com portal.orders.view_all, todos da empresa).
 */
final readonly class BuyerReturns
{
    public function __construct(private BuyerOrders $orders) {}

    public function actor(): BuyerActor
    {
        return $this->orders->actor();
    }

    /**
     * @return Builder<ReturnRequest>
     */
    public function query(BuyerActor $actor): Builder
    {
        return ReturnRequest::query()
            ->where('buyer_company_id', $actor->company->id)
            ->when(! $this->orders->seesAll(), static fn (Builder $query) => $query->where('requested_by_membership_id', $actor->membership->id));
    }

    public function find(BuyerActor $actor, mixed $returnId): ReturnRequest
    {
        return $this->query($actor)->whereKey(UntrustedId::uuid($returnId, ReturnRequest::class))->firstOrFail();
    }
}
