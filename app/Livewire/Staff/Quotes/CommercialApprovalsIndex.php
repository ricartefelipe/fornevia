<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Quotes;

use App\Actions\Quotes\Staff\StaffQuoteQuery;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Models\Quotes\QuoteVersion;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fila de aprovação comercial (RN-041): versões fora da política
 * aguardando decisão, com o maior desconto, os motivos e a justificativa.
 * As acima do limite de quem consulta aparecem só para leitura. A decisão
 * é tomada no detalhe da cotação, sobre a versão exibida.
 */
#[Layout('layouts.app')]
#[Title('Aprovações comerciais')]
final class CommercialApprovalsIndex extends Component
{
    public function render(StaffQuoteQuery $quotes, Access $access): View
    {
        $access->authorize(Permission::PricingApprove);
        $versions = QuoteVersion::query()
            ->with(['quote.buyerCompany', 'review'])
            ->where('status', QuoteVersionStatus::PendingApproval->value)
            ->whereIn('quote_id', $quotes->quotes()->select('id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return view('livewire.staff.quotes.commercial-approvals-index', [
            'versions' => $versions,
            'limit' => $access->maxDiscountBps(),
            'membershipId' => $access->membership()->id,
        ]);
    }
}
