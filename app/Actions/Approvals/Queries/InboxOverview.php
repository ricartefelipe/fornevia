<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Domain\Shared\Money;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Fila de aprovação: linhas da aba escolhida, valor somado da aba (pedidos
 * pendentes) e quantidade de pedidos em cada aba.
 */
final readonly class InboxOverview
{
    /**
     * @param  LengthAwarePaginator<int, InboxEntry>  $entries
     * @param  array<string, int>  $counts  aba => quantidade (só as abas visíveis ao ator)
     */
    public function __construct(
        public InboxTab $tab,
        public LengthAwarePaginator $entries,
        public ?Money $amount,
        public array $counts,
    ) {}

    public function count(InboxTab $tab): ?int
    {
        return $this->counts[$tab->value] ?? null;
    }
}
