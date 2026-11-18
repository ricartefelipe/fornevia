<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Credit;

use App\Actions\Credit\CreditAccountRecords;
use App\Livewire\Staff\Credit\Concerns\InteractsWithCreditAccount;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Históricos da conta de crédito: movimentos do ledger (comprometimentos,
 * liberações, faturamento, pagamentos) e alterações de limite e situação,
 * com responsável e justificativa.
 */
final class CreditHistory extends Component
{
    use InteractsWithCreditAccount;
    use WithPagination;

    public const string MOVEMENTS_PAGE = 'movimentos';

    public const string CHANGES_PAGE = 'alteracoes';

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    #[On(self::UPDATED_EVENT)]
    public function refreshHistory(): void
    {
        $this->resetPage(self::CHANGES_PAGE);
    }

    public function render(CreditAccountRecords $records): View
    {
        $account = $this->account();

        return view('livewire.staff.credit.credit-history', [
            'movements' => $account !== null ? $records->movements($account, 15, self::MOVEMENTS_PAGE) : null,
            'changes' => $account !== null ? $records->limitChanges($account, 10, self::CHANGES_PAGE) : null,
        ]);
    }
}
