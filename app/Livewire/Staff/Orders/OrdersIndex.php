<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Orders;

use App\Actions\Ordering\Staff\ExportOrders;
use App\Actions\Ordering\Staff\StaffOrderQuery;
use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\Money;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Enums\Ordering\FinancialStatus;
use App\Enums\Ordering\FulfillmentStatus;
use App\Models\Ordering\Order;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pedidos do distribuidor (RN-062): filtros pelos quatro estados
 * independentes, empresa compradora, período (data do pedido no fuso
 * comercial) e busca por número ou ordem de compra, com totais da página
 * e exportação CSV do resultado filtrado.
 * A aprovação interna do comprador aparece com o nível em análise, somente
 * para leitura.
 *
 * Toda consulta passa por StaffOrderQuery: o representante comercial só vê
 * pedidos e empresas da própria carteira. Valores de filtro inválidos
 * vindos da URL são ignorados.
 */
#[Layout('layouts.app')]
#[Title('Pedidos')]
final class OrdersIndex extends Component
{
    use WithPagination;

    public const int PER_PAGE = 25;

    private const array FILTERS = ['search', 'commercialStatus', 'approvalStatus', 'financialStatus', 'fulfillmentStatus', 'company', 'dateFrom', 'dateUntil'];

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'situacao', except: '')]
    public string $commercialStatus = '';

    #[Url(as: 'aprovacao', except: '')]
    public string $approvalStatus = '';

    #[Url(as: 'financeiro', except: '')]
    public string $financialStatus = '';

    #[Url(as: 'atendimento', except: '')]
    public string $fulfillmentStatus = '';

    /** Empresa filtrada (entrada não confiável; ignorada fora do formato UUID). */
    #[Url(as: 'empresa', except: '')]
    public string $company = '';

    #[Url(as: 'de', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'ate', except: '')]
    public string $dateUntil = '';

    public string $companySearch = '';

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }

        if ($property === 'dateFrom' || $property === 'dateUntil') {
            $this->resetErrorBag(['dateFrom', 'dateUntil']);
            $this->validate([
                'dateFrom' => ['nullable', ...BusinessDate::rules()],
                'dateUntil' => ['nullable', ...BusinessDate::rules(), ...(self::date($this->dateFrom) !== null ? ['after_or_equal:dateFrom'] : [])],
            ], [], ['dateFrom' => 'data inicial', 'dateUntil' => 'data final']);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->reset('companySearch');
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function render(StaffOrderQuery $query): View
    {
        $orders = $this->orders($query);
        $page = $orders->items();

        return view('livewire.staff.orders.orders-index', [
            'orders' => $orders,
            'pageTotal' => self::pageTotal($page),
            'pageCount' => count($page),
            'companyOptions' => $this->companyOptions($query),
            'commercialOptions' => self::options(CommercialStatus::cases()),
            'approvalOptions' => self::options(ApprovalStatus::cases()),
            'financialOptions' => self::options(FinancialStatus::cases()),
            'fulfillmentOptions' => self::options(FulfillmentStatus::cases()),
            'portfolioOnly' => $query->portfolioMembershipId() !== null,
            'filtered' => $this->hasFilters(),
        ]);
    }

    /**
     * Exporta em CSV os pedidos que atendem aos filtros (RF-113).
     */
    public function export(StaffOrderQuery $query, ExportOrders $export): ?StreamedResponse
    {
        try {
            $csv = $export->handle($this->filtered($query), array_combine(self::FILTERS, array_map(fn (string $f): string => (string) $this->{$f}, self::FILTERS)));
        } catch (InvalidIntegration $e) {
            $this->addError('export', $e->getMessage());

            return null;
        }

        $name = 'pedidos-'.CarbonImmutable::now((string) config('app.business_timezone'))->format('Ymd-His').'.csv';

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    private function orders(StaffOrderQuery $query): LengthAwarePaginator
    {
        return $this->filtered($query)->with(['buyerCompany', 'approvalSteps'])->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE);
    }

    /**
     * Pedidos visíveis que atendem aos filtros, sem ordenação.
     *
     * @return Builder<Order>
     */
    private function filtered(StaffOrderQuery $query): Builder
    {
        $orders = $query->orders();

        foreach ([
            'commercial_status' => CommercialStatus::tryFrom($this->commercialStatus),
            'approval_status' => ApprovalStatus::tryFrom($this->approvalStatus),
            'financial_status' => FinancialStatus::tryFrom($this->financialStatus),
            'fulfillment_status' => FulfillmentStatus::tryFrom($this->fulfillmentStatus),
        ] as $column => $status) {
            if ($status !== null) {
                $orders->where($column, $status->value);
            }
        }

        if (Str::isUuid($this->company)) {
            $orders->where('buyer_company_id', strtolower($this->company));
        }

        $timezone = (string) config('app.business_timezone');
        $from = self::date($this->dateFrom);
        $until = self::date($this->dateUntil);

        if ($from !== null) {
            $orders->where('created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->format('Y-m-d H:i:sP'));
        }

        if ($until !== null) {
            $orders->where('created_at', '<', CarbonImmutable::parse($until, $timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:sP'));
        }

        $term = trim(mb_substr($this->search, 0, 60));

        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $orders->where(static fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('purchase_order_reference', 'ilike', $like));
        }

        return $orders;
    }

    /**
     * Empresas da busca (até 25, no escopo visível) e a já selecionada.
     *
     * @return array<string, string>
     */
    private function companyOptions(StaffOrderQuery $query): array
    {
        $term = trim(mb_substr($this->companySearch, 0, 100));
        $companies = $query->companies()->orderBy('trade_name')->orderBy('id')->limit(25);

        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $digits = (string) preg_replace('/\D/', '', $term);

            $companies->where(static function (Builder $q) use ($like, $digits): void {
                $q->where('trade_name', 'ilike', $like)->orWhere('legal_name', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $q->orWhere('document', 'like', '%'.$digits.'%');
                }
            });
        }

        $options = [];

        foreach ($companies->get() as $company) {
            $options[$company->id] = $company->trade_name.' · '.Cnpj::format($company->document);
        }

        if (Str::isUuid($this->company) && ! isset($options[strtolower($this->company)])) {
            $selected = $query->companies()->find(strtolower($this->company));

            if ($selected !== null) {
                $options = [$selected->id => $selected->trade_name.' · '.Cnpj::format($selected->document)] + $options;
            }
        }

        return $options;
    }

    private function hasFilters(): bool
    {
        foreach (self::FILTERS as $filter) {
            if (trim($this->{$filter}) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Data digitada (Y-m-d) dentro do intervalo comercial, ou null.
     */
    private static function date(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || $value < BusinessDate::EARLIEST || $value > BusinessDate::LATEST) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    /**
     * @param  array<int, CommercialStatus|ApprovalStatus|FinancialStatus|FulfillmentStatus>  $cases
     * @return array<string, string>
     */
    private static function options(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Soma da página; null quando excede o limite de valores monetários.
     *
     * @param  array<int, Order>  $page
     */
    private static function pageTotal(array $page): ?Money
    {
        try {
            return Money::sum(array_map(static fn (Order $order): Money => $order->total_cents, $page));
        } catch (AmountOutOfRange) {
            return null;
        }
    }
}
