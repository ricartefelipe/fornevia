<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Portal;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\BuyerActor;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteStatus;
use App\Models\Quotes\Quote;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Cotações visíveis ao comprador: as próprias (solicitadas pela
 * associação) ou, com portal.orders.view_all, todas da empresa. A RLS
 * restringe à empresa do contexto; o filtro é repetido. Fora desse alcance
 * equivale a inexistente.
 */
final readonly class BuyerQuotes
{
    public const int PER_PAGE = 20;

    /** Abas da lista: aguardando o comprador, com o distribuidor, encerradas. */
    public const array TABS = ['awaiting' => [QuoteStatus::Proposed], 'distributor' => [QuoteStatus::Requested, QuoteStatus::Expired], 'closed' => [QuoteStatus::Accepted, QuoteStatus::Rejected, QuoteStatus::Cancelled]];

    public function __construct(private PortalBuyer $buyer) {}

    public function actor(): BuyerActor
    {
        return $this->buyer->require(Permission::PortalQuotes);
    }

    public function seesAll(): bool
    {
        return $this->buyer->can(Permission::PortalOrdersViewAll);
    }

    /**
     * @return LengthAwarePaginator<int, Quote>
     */
    public function paginate(BuyerActor $actor, string $tab, int $page = 1): LengthAwarePaginator
    {
        $statuses = self::TABS[$tab] ?? self::TABS['awaiting'];

        return $this->visible($actor)
            ->whereIn('status', array_map(static fn (QuoteStatus $s): string => $s->value, $statuses))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * @return array<string, int> aba => quantidade
     */
    public function counts(BuyerActor $actor): array
    {
        $counts = [];

        foreach (self::TABS as $tab => $statuses) {
            $counts[$tab] = $this->visible($actor)->whereIn('status', array_map(static fn (QuoteStatus $s): string => $s->value, $statuses))->count();
        }

        return $counts;
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(BuyerActor $actor, mixed $quoteId): Quote
    {
        return $this->visible($actor)->whereKey(UntrustedId::uuid($quoteId, Quote::class))->firstOrFail();
    }

    /**
     * @return Builder<Quote>
     */
    private function visible(BuyerActor $actor): Builder
    {
        return Quote::query()
            ->where('buyer_company_id', $actor->company->id)
            ->when(! $this->seesAll(), static fn (Builder $query) => $query->where('requested_by_membership_id', $actor->membership->id));
    }
}
