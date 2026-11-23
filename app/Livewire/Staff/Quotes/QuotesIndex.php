<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Quotes;

use App\Actions\Quotes\Staff\StaffQuoteQuery;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Quotes\QuoteVersion;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cotações da equipe: filas "para responder", "em aprovação comercial",
 * "aguardando cliente", "vencendo em 48 h", "com reserva ativa" e
 * "encerradas", com busca por número ou empresa. O representante vê só a
 * carteira dele (StaffQuoteQuery).
 */
#[Layout('layouts.app')]
#[Title('Cotações')]
final class QuotesIndex extends Component
{
    use WithPagination;

    public const array FILTERS = [
        'respond' => 'Para responder',
        'approval' => 'Em aprovação comercial',
        'customer' => 'Aguardando cliente',
        'expiring' => 'Vencendo em 48 h',
        'hold' => 'Com reserva ativa',
        'closed' => 'Encerradas',
    ];

    #[Url(as: 'fila', except: 'respond')]
    public string $filter = 'respond';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['filter', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function paginationView(): string
    {
        return 'livewire.portal.ordering.pagination';
    }

    public function render(StaffQuoteQuery $quotes, Clock $clock, Access $access): View
    {
        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'respond';
        }

        $now = $clock->now();
        $search = mb_substr(trim($this->search), 0, 60);
        $preparing = static fn (Builder $q, array $statuses): Builder => $q->whereExists(static fn ($v) => $v->selectRaw('1')->from('quote_versions')
            ->whereColumn('quote_versions.quote_id', 'quotes.id')->whereIn('quote_versions.status', $statuses));

        $query = $quotes->quotes()->with('buyerCompany')->orderByDesc('last_activity_at')->orderByDesc('id');
        match ($this->filter) {
            'respond' => $query->whereIn('status', [QuoteStatus::Requested->value, QuoteStatus::Expired->value])
                ->whereNotExists(static fn ($v) => $v->selectRaw('1')->from('quote_versions')->whereColumn('quote_versions.quote_id', 'quotes.id')
                    ->where('quote_versions.status', QuoteVersionStatus::PendingApproval->value)),
            'approval' => $preparing($query, [QuoteVersionStatus::PendingApproval->value]),
            'customer' => $query->where('status', QuoteStatus::Proposed->value),
            'expiring' => $query->where('status', QuoteStatus::Proposed->value)->whereExists(static fn ($v) => $v->selectRaw('1')->from('quote_versions')
                ->whereColumn('quote_versions.quote_id', 'quotes.id')->where('quote_versions.status', QuoteVersionStatus::Sent->value)
                ->whereBetween('quote_versions.valid_until', [$now, $now->addHours(48)])),
            'hold' => $query->whereExists(static fn ($v) => $v->selectRaw('1')->from('quote_versions')
                ->whereColumn('quote_versions.quote_id', 'quotes.id')->where('quote_versions.stock_hold_status', StockHoldStatus::Active->value)),
            default => $query->whereIn('status', [QuoteStatus::Accepted->value, QuoteStatus::Rejected->value, QuoteStatus::Cancelled->value]),
        };

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(static fn (Builder $q) => $q->where('number', 'ilike', $like)
                ->orWhereHas('buyerCompany', static fn (Builder $c) => $c->where('trade_name', 'ilike', $like)->orWhere('legal_name', 'ilike', $like)));
        }

        $page = $query->paginate(20, ['*'], 'page', (int) (filter_var($this->getPage(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1));
        $current = QuoteVersion::query()->whereIn('quote_id', $page->getCollection()->pluck('id')->all())
            ->whereIn('status', [QuoteVersionStatus::Sent->value, QuoteVersionStatus::Accepted->value, QuoteVersionStatus::PendingApproval->value, QuoteVersionStatus::Ready->value])
            ->orderByDesc('created_at')->get()->unique('quote_id')->keyBy('quote_id');

        return view('livewire.staff.quotes.quotes-index', [
            'quotes' => $page, 'current' => $current, 'filters' => self::FILTERS, 'now' => $now, 'canApprove' => $access->can(Permission::PricingApprove),
        ]);
    }
}
