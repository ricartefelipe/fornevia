<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Staff;

use App\Enums\Identity\Permission;
use App\Models\Quotes\Quote;
use App\Support\Auth\Access;
use App\Support\Auth\SalesPortfolio;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cotações visíveis à equipe do distribuidor: exige quotes.view; o
 * representante comercial vê só as empresas da carteira dele hoje, como
 * nos pedidos (StaffOrderQuery). Fora do alcance equivale a inexistente.
 */
final readonly class StaffQuoteQuery
{
    public function __construct(private Access $access, private SalesPortfolio $portfolio) {}

    /**
     * @return Builder<Quote>
     */
    public function quotes(): Builder
    {
        $this->access->authorize(Permission::QuotesView);

        return $this->portfolio->restrict(Quote::query());
    }

    public function find(mixed $quoteId): Quote
    {
        return $this->quotes()->findOrFail(UntrustedId::uuid($quoteId, Quote::class));
    }
}
