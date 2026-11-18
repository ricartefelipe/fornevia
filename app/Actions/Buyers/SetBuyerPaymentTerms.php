<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Domain\Buyers\Exceptions\InvalidPaymentTerms;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Define as condições de pagamento permitidas para a empresa compradora e
 * a condição padrão.
 *
 * - somente condições ativas do próprio tenant;
 * - lista não vazia exige condição padrão, que precisa pertencer à lista;
 * - lista vazia remove todas as condições (a empresa passa a não ter
 *   condição permitida).
 *
 * A troca ocorre sob bloqueio da empresa; o padrão anterior é desmarcado
 * antes de marcar o novo (índice único parcial por empresa).
 */
final readonly class SetBuyerPaymentTerms
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private BuyerRecords $records,
    ) {}

    /**
     * @param  array<array-key, mixed>  $termIds
     * @return Collection<int, BuyerPaymentTerm>
     */
    public function handle(BuyerCompany $company, array $termIds, ?string $defaultId): Collection
    {
        $this->access->authorize(Permission::BuyersManage);

        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): string => UntrustedId::uuid($id, PaymentTerm::class),
            $termIds,
        )));
        $defaultId = $defaultId !== null && $defaultId !== '' ? UntrustedId::uuid($defaultId, PaymentTerm::class) : null;

        if ($ids === [] && $defaultId !== null) {
            throw new InvalidPaymentTerms('A condição padrão precisa estar entre as condições permitidas.', ['field' => 'default']);
        }

        if ($ids !== [] && $defaultId === null) {
            throw new InvalidPaymentTerms('Escolha a condição de pagamento padrão.', ['field' => 'default']);
        }

        if ($defaultId !== null && ! in_array($defaultId, $ids, true)) {
            throw new InvalidPaymentTerms('A condição padrão precisa estar entre as condições permitidas.', ['field' => 'default']);
        }

        return DB::transaction(function () use ($company, $ids, $defaultId): Collection {
            $company = $this->records->lockCompany($company);

            $terms = PaymentTerm::query()->whereIn('id', $ids)->orderBy('code')->get()->keyBy('id');

            if ($terms->count() !== count($ids)) {
                throw (new ModelNotFoundException)->setModel(PaymentTerm::class, array_values(array_diff($ids, $terms->keys()->all())));
            }

            $inactive = $terms->reject(static fn (PaymentTerm $term): bool => $term->is_active);

            if ($inactive->isNotEmpty()) {
                throw new InvalidPaymentTerms(
                    'Condições inativas não podem ser permitidas: '.$inactive->pluck('name')->implode(', ').'.',
                    ['field' => 'terms'],
                );
            }

            $current = BuyerPaymentTerm::query()
                ->with('paymentTerm')
                ->where('buyer_company_id', $company->id)
                ->get();
            $before = $this->describe($current);

            BuyerPaymentTerm::query()
                ->where('buyer_company_id', $company->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            BuyerPaymentTerm::query()
                ->where('buyer_company_id', $company->id)
                ->whereNotIn('payment_term_id', $ids)
                ->delete();

            $assigned = $current->pluck('payment_term_id')->all();

            foreach ($ids as $id) {
                if (in_array($id, $assigned, true)) {
                    continue;
                }

                $row = new BuyerPaymentTerm;
                $row->forceFill([
                    'buyer_company_id' => $company->id,
                    'payment_term_id' => $id,
                    'is_default' => false,
                ])->save();
            }

            if ($defaultId !== null) {
                BuyerPaymentTerm::query()
                    ->where('buyer_company_id', $company->id)
                    ->where('payment_term_id', $defaultId)
                    ->update(['is_default' => true]);
            }

            $result = BuyerPaymentTerm::query()
                ->with('paymentTerm')
                ->where('buyer_company_id', $company->id)
                ->get();
            $after = $this->describe($result);

            if ($before !== $after) {
                $this->audit->record('buyer_company.payment_terms_changed', $company, [
                    'buyer_company_id' => $company->id,
                    'before' => $before,
                    'after' => $after,
                ]);
            }

            return $result;
        });
    }

    /**
     * @param  Collection<int, BuyerPaymentTerm>  $rows
     * @return array{terms: list<string>, default: string|null}
     */
    private function describe(Collection $rows): array
    {
        $codes = array_values($rows->map(static fn (BuyerPaymentTerm $row): string => $row->paymentTerm->code)->sort()->all());
        $default = $rows->first(static fn (BuyerPaymentTerm $row): bool => $row->is_default);

        return ['terms' => $codes, 'default' => $default?->paymentTerm->code];
    }
}
