<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\Exceptions\JustificationRequired;
use App\Domain\Pricing\Exceptions\NegotiatedPriceNotActive;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Models\Pricing\NegotiatedPrice;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Revoga uma condição negociada com justificativa (registrada na
 * auditoria). A condição revogada deixa de valer imediatamente e libera a
 * vigência para uma nova condição do mesmo par empresa/SKU.
 */
final readonly class RevokeNegotiatedPrice
{
    public const int MIN_REASON_LENGTH = 5;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $version,
    ) {}

    public function handle(NegotiatedPrice $negotiated, string $reason): NegotiatedPrice
    {
        $this->access->authorize(Permission::PricingManage);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH || mb_strlen($reason) > 500) {
            throw new JustificationRequired(
                'Informe a justificativa da revogação (de '.self::MIN_REASON_LENGTH.' a 500 caracteres).',
                ['field' => 'reason'],
            );
        }

        return DB::transaction(function () use ($negotiated, $reason): NegotiatedPrice {
            $locked = NegotiatedPrice::query()->lockForUpdate()->findOrFail($negotiated->id);

            if ($locked->status !== NegotiatedPriceStatus::Active) {
                throw new NegotiatedPriceNotActive('Esta condição negociada já foi revogada.');
            }

            $locked->forceFill(['status' => NegotiatedPriceStatus::Revoked])->save();

            $this->audit->record('negotiated_price.revoked', $locked, [
                'buyer_company_id' => $locked->buyer_company_id,
                'sku_id' => $locked->sku_id,
                'unit_price_cents' => $locked->unit_price_cents->cents,
                'valid_from' => $locked->valid_from->toDateString(),
                'valid_until' => $locked->valid_until?->toDateString(),
            ], $reason);
            $this->version->bump();

            return $locked;
        });
    }
}
