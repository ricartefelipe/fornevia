<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Quotes;

use App\Actions\Quotes\Portal\BuyerQuotes;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Models\Identity\User;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteVersion;
use App\Support\Clock\Clock;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cotações do comprador em abas: aguardando a resposta dele, com o
 * distribuidor e encerradas. Mostra a proposta vigente (versão, total,
 * validade) e se ela reserva estoque e até quando (RN-043).
 */
#[Layout('layouts.app')]
#[Title('Cotações')]
final class QuotesIndex extends Component
{
    use WithPagination;

    #[Url(as: 'aba', except: 'awaiting')]
    public string $tab = 'awaiting';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'livewire.portal.ordering.pagination';
    }

    public function render(BuyerQuotes $quotes, Clock $clock): View
    {
        if (! array_key_exists($this->tab, BuyerQuotes::TABS)) {
            $this->tab = 'awaiting';
        }

        $actor = $quotes->actor();
        $page = $quotes->paginate($actor, $this->tab, (int) (filter_var($this->getPage(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1));
        $ids = $page->getCollection()->pluck('id')->all();
        /** @var \Illuminate\Support\Collection<string, QuoteVersion> $current */
        $current = QuoteVersion::query()->whereIn('quote_id', $ids)->whereNotNull('version_number')
            ->orderByDesc('version_number')->get()->unique('quote_id')->keyBy('quote_id');
        $requesters = $quotes->seesAll()
            ? User::query()->whereIn('id', $page->getCollection()->pluck('requested_by_user_id')->unique()->all())->pluck('name', 'id')->all()
            : [];

        return view('livewire.portal.quotes.quotes-index', [
            'quotes' => $page,
            'current' => $current,
            'counts' => $quotes->counts($actor),
            'seesAll' => $quotes->seesAll(),
            'requesters' => $requesters,
            'now' => $clock->now(),
            'sentStatus' => QuoteVersionStatus::Sent,
        ]);
    }

    public static function effectiveLabel(Quote $quote, ?QuoteVersion $version, \Carbon\CarbonImmutable $now): string
    {
        if ($quote->status === \App\Enums\Quotes\QuoteStatus::Proposed && $version !== null && $version->valid_until->lessThanOrEqualTo($now)) {
            return 'Vencida';
        }

        return match ($quote->status) {
            \App\Enums\Quotes\QuoteStatus::Proposed => 'Proposta recebida',
            \App\Enums\Quotes\QuoteStatus::Requested => 'Aguardando proposta',
            \App\Enums\Quotes\QuoteStatus::Cancelled => $quote->close_reason_code === \App\Enums\Quotes\QuoteCloseReason::StaffClosed ? 'Encerrada pelo distribuidor' : 'Cancelada',
            default => $quote->status->label(),
        };
    }
}
