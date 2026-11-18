<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\Exceptions\InvalidUnitPrice;
use App\Domain\Pricing\Exceptions\InvalidValidityPeriod;
use App\Domain\Pricing\Exceptions\NegotiatedPriceOverlap;
use App\Domain\Pricing\Exceptions\SkuNotPriceable;
use App\Domain\Pricing\Exceptions\SkuPackagingChanged;
use App\Domain\Shared\Money;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Models\Pricing\NegotiatedPrice;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use App\Support\Tenancy\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registra uma condição negociada (preço contratado) para uma empresa e um
 * SKU, aprovada pelo usuário atual. Tem precedência sobre todas as tabelas
 * (ADR-011).
 *
 * A sobreposição de vigência entre condições ativas do mesmo par
 * empresa/SKU é recusada pelo próprio banco (exclusion constraint), o que
 * também cobre gravações concorrentes. A inserção roda em savepoint para
 * que a transação externa continue utilizável após a recusa.
 */
final readonly class SaveNegotiatedPrice
{
    private const string EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $version,
        private CurrentContext $context,
    ) {}

    public function handle(
        BuyerCompany $buyer,
        Sku $sku,
        Money $unitPrice,
        CarbonImmutable $validFrom,
        ?CarbonImmutable $validUntil = null,
        ?string $notes = null,
        ?string $expectedPackaging = null,
    ): NegotiatedPrice {
        $this->access->authorize(Permission::PricingManage);

        if (! $unitPrice->isPositive()) {
            throw new InvalidUnitPrice('O preço negociado deve ser maior que zero.', ['field' => 'unit_price']);
        }

        $from = $validFrom->toDateString();
        $until = $validUntil?->toDateString();

        if ($until !== null && $until < $from) {
            throw new InvalidValidityPeriod('O término da vigência não pode ser anterior ao início.');
        }

        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        try {
            return DB::transaction(function () use ($buyer, $sku, $unitPrice, $from, $until, $notes, $expectedPackaging): NegotiatedPrice {
                $company = BuyerCompany::query()->findOrFail($buyer->id);
                // Compartilhado: serializa com a troca de embalagem em SaveSku.
                $sku = Sku::query()->sharedLock()->findOrFail($sku->id);

                if ($sku->status === SkuStatus::Archived) {
                    throw new SkuNotPriceable("O SKU {$sku->code} está arquivado e não pode receber preço.", ['field' => 'sku']);
                }

                if ($expectedPackaging !== null && $expectedPackaging !== $sku->packagingFingerprint()) {
                    throw SkuPackagingChanged::forSku($sku->code, $sku->salesUnit()->firstOrFail()->code);
                }

                $price = new NegotiatedPrice;
                $price->forceFill([
                    'buyer_company_id' => $company->id,
                    'sku_id' => $sku->id,
                    'unit_price_cents' => $unitPrice,
                    'valid_from' => $from,
                    'valid_until' => $until,
                    'source' => 'manual',
                    'status' => NegotiatedPriceStatus::Active,
                    'approved_by_user_id' => $this->context->require()->userId,
                    'notes' => $notes,
                ])->save();

                $this->audit->record('negotiated_price.created', $price, [
                    'buyer_company_id' => $company->id,
                    'sku_id' => $sku->id,
                    'sku_code' => $sku->code,
                    'unit_price_cents' => $unitPrice->cents,
                    'valid_from' => $from,
                    'valid_until' => $until,
                ]);
                $this->version->bump();

                return $price;
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== self::EXCLUSION_VIOLATION) {
                throw $e;
            }

            throw $this->overlap($buyer->id, $sku->id, $from, $until);
        }
    }

    private function overlap(string $buyerId, string $skuId, string $from, ?string $until): NegotiatedPriceOverlap
    {
        $existing = NegotiatedPrice::query()
            ->where('buyer_company_id', $buyerId)
            ->where('sku_id', $skuId)
            ->where('status', NegotiatedPriceStatus::Active->value)
            ->when($until !== null, static fn (Builder $q) => $q->where('valid_from', '<=', $until))
            ->where(static fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $from))
            ->orderBy('valid_from')
            ->first();

        if ($existing === null) {
            return new NegotiatedPriceOverlap(
                'Já existe condição negociada ativa com vigência sobreposta para esta empresa e este SKU.',
            );
        }

        $period = $existing->valid_until !== null
            ? 'de '.$existing->valid_from->format('d/m/Y').' a '.$existing->valid_until->format('d/m/Y')
            : 'a partir de '.$existing->valid_from->format('d/m/Y').', sem término';

        return new NegotiatedPriceOverlap(
            "Já existe condição negociada ativa para esta empresa e este SKU {$period} ({$existing->unit_price_cents->format()}). "
            .'Revogue-a ou ajuste a vigência para que os períodos não se sobreponham.',
            ['conflicting_id' => $existing->id],
        );
    }
}
