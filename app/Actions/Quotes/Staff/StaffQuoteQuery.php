<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Staff;

use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Quotes\Quote;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cotações visíveis à equipe do distribuidor: exige quotes.view; o
 * representante comercial vê só as empresas da carteira dele hoje, como
 * nos pedidos (StaffOrderQuery). Fora do alcance equivale a inexistente.
 */
final readonly class StaffQuoteQuery
{
    public function __construct(private Access $access) {}

    /**
     * @return Builder<Quote>
     */
    public function quotes(): Builder
    {
        $this->access->authorize(Permission::QuotesView);
        $membership = $this->access->membership();
        $query = Quote::query();

        if ($membership->role->key === SystemRole::SalesRep->value) {
            $query->whereIn('buyer_company_id', BuyerCompany::query()->select('id')->where('sales_rep_membership_id', $membership->id));
        }

        return $query;
    }

    public function find(mixed $quoteId): Quote
    {
        return $this->quotes()->findOrFail(UntrustedId::uuid($quoteId, Quote::class));
    }
}
