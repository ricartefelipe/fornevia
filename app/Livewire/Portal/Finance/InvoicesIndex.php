<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Finance;

use App\Actions\Finance\Portal\BuyerInvoices;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Faturas do comprador, da mais recente para a mais antiga.
 */
#[Layout('layouts.app')]
#[Title('Faturas')]
final class InvoicesIndex extends Component
{
    use WithPagination;

    public function render(BuyerInvoices $invoices): View
    {
        return view('livewire.portal.finance.invoices-index', [
            'invoices' => $invoices->query($invoices->actor())->with(['order', 'installments'])->orderByDesc('issue_date')->orderByDesc('id')->paginate(20),
        ]);
    }
}
