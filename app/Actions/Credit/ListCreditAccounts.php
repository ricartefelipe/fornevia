<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Data\Credit\CreditAccountFilters;
use App\Data\Credit\CreditAccountSummary;
use App\Domain\Shared\Cnpj;
use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Support\Auth\Access;
use App\Support\Auth\SalesPortfolio;
use App\Support\Clock\Clock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lista de crédito por empresa compradora (inclusive empresas sem conta),
 * em uma única consulta paginada: conta, saldos da projeção e soma das
 * exceções vigentes no instante atual.
 *
 * O filtro "disponível abaixo de" aplica no SQL a fórmula de RN-080
 * (limite + exceções vigentes − comprometido − recebível), com o mesmo
 * critério de vigência do CreditLedger; os valores exibidos são calculados
 * por CreditPosition a partir das mesmas colunas.
 */
final readonly class ListCreditAccounts
{
    private const string AVAILABLE_SQL = '(ca.limit_cents + coalesce(ex.total, 0) - ca.committed_cents - ca.receivable_cents)';

    public function __construct(
        private Access $access,
        private Clock $clock,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CreditAccountSummary>
     */
    public function handle(CreditAccountFilters $filters, int $perPage = 25, string $pageName = 'page'): LengthAwarePaginator
    {
        $this->access->authorize(Permission::CreditView);

        $now = $this->clock->now();
        $activeExceptions = DB::table('credit_exceptions as ce')
            ->selectRaw('sum(ce.amount_cents) as total')
            ->whereColumn('ce.credit_account_id', 'ca.id')
            ->whereColumn('ce.tenant_id', 'ca.tenant_id')
            ->whereNull('ce.revoked_at')
            ->where('ce.valid_from', '<=', $now)
            ->where('ce.valid_until', '>', $now);

        $query = app(SalesPortfolio::class)->companies(BuyerCompany::query())
            ->leftJoin('credit_accounts as ca', static function ($join): void {
                $join->on('ca.buyer_company_id', '=', 'buyer_companies.id')
                    ->on('ca.tenant_id', '=', 'buyer_companies.tenant_id');
            })
            ->leftJoinLateral($activeExceptions, 'ex')
            ->select([
                'buyer_companies.id',
                'buyer_companies.trade_name',
                'buyer_companies.legal_name',
                'buyer_companies.document',
                'buyer_companies.status',
                'ca.id as account_id',
                'ca.status as account_status',
                'ca.limit_cents as account_limit_cents',
                'ca.committed_cents as account_committed_cents',
                'ca.receivable_cents as account_receivable_cents',
            ])
            ->selectRaw('coalesce(ex.total, 0) as account_exceptions_cents')
            ->orderBy('buyer_companies.trade_name')
            ->orderBy('buyer_companies.id');

        $this->applySearch($query, $filters->search);

        if ($filters->situation === CreditAccountFilters::WITHOUT_ACCOUNT) {
            $query->whereNull('ca.id');
        } elseif (($status = CreditAccountStatus::tryFrom((string) $filters->situation)) !== null) {
            $query->where('ca.status', $status->value);
        }

        if ($filters->availableBelow !== null) {
            $query->whereNotNull('ca.id')->whereRaw(self::AVAILABLE_SQL.' < ?', [$filters->availableBelow->cents]);
        }

        return $query->paginate($perPage, pageName: $pageName)->through(static fn (BuyerCompany $row): CreditAccountSummary => self::summary($row));
    }

    /**
     * @param  Builder<BuyerCompany>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $term = trim(mb_substr($search, 0, 100));

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        // Busca por CNPJ (numérico ou alfanumérico) quando o termo parece um documento.
        $digits = (string) Cnpj::searchKey($term);
        $byDocument = $digits !== '';

        $query->where(static function (Builder $query) use ($like, $digits, $byDocument): void {
            $query->where('buyer_companies.legal_name', 'ilike', $like)->orWhere('buyer_companies.trade_name', 'ilike', $like);

            if ($byDocument) {
                $query->orWhere('buyer_companies.document', 'like', '%'.$digits.'%');
            }
        });
    }

    private static function summary(BuyerCompany $row): CreditAccountSummary
    {
        $accountId = $row->getAttribute('account_id');
        $status = CreditAccountStatus::tryFrom((string) $row->getAttribute('account_status'));
        $hasAccount = is_string($accountId) && $status !== null;

        return CreditAccountSummary::make(
            $row->id,
            $row->trade_name,
            $row->legal_name,
            $row->document,
            $row->status,
            $hasAccount ? $accountId : null,
            $hasAccount ? $status : null,
            $hasAccount ? [
                'limit' => self::cents($row->getAttribute('account_limit_cents')),
                'exceptions' => self::cents($row->getAttribute('account_exceptions_cents')),
                'committed' => self::cents($row->getAttribute('account_committed_cents')),
                'receivable' => self::cents($row->getAttribute('account_receivable_cents')),
            ] : null,
        );
    }

    /**
     * bigint e numeric (soma) chegam como inteiro ou texto de dígitos.
     */
    private static function cents(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : 0;
    }
}
