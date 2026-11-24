<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidFreightRule;
use App\Domain\Shared\Money;
use App\Enums\Fulfillment\FreightConditionKind;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Fulfillment\BuyerFreightCondition;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Condição de frete negociada com a empresa compradora (RF-092): isento ou
 * valor fixo por pedido; sem condição (null), valem as regras do
 * distribuidor.
 */
final readonly class SaveBuyerFreightCondition
{
    public function __construct(private Access $access, private AuditRecorder $audit) {}

    public function handle(mixed $buyerCompanyId, ?FreightConditionKind $kind, ?string $amount = null): ?BuyerFreightCondition
    {
        $this->access->authorize(Permission::PricingManage);
        $buyer = BuyerCompany::query()->findOrFail(UntrustedId::uuid($buyerCompanyId, BuyerCompany::class));
        $value = Money::zero();

        if ($kind === FreightConditionKind::Fixed) {
            try {
                $value = Money::parse(str_replace(['R$', ' '], '', (string) $amount));
            } catch (InvalidArgumentException) {
                throw new InvalidFreightRule('Valor inválido. Use o formato 1.234,56.', ['field' => 'condition_amount']);
            }

            if ($value->isNegative()) {
                throw new InvalidFreightRule('O valor do frete não pode ser negativo.', ['field' => 'condition_amount']);
            }
        }

        return DB::transaction(function () use ($buyer, $kind, $value): ?BuyerFreightCondition {
            $condition = BuyerFreightCondition::query()->where('buyer_company_id', $buyer->id)->lockForUpdate()->first();

            if ($kind === null) {
                $condition?->delete();
                $this->audit->record('buyer_freight_condition.removed', $buyer, ['buyer_company_id' => $buyer->id]);

                return null;
            }

            $condition ??= (new BuyerFreightCondition)->forceFill(['buyer_company_id' => $buyer->id]);
            $condition->forceFill(['kind' => $kind, 'amount_cents' => $value])->save();
            $this->audit->record('buyer_freight_condition.saved', $condition, ['kind' => $kind->value, 'amount_cents' => $value->cents, 'buyer_company_id' => $buyer->id]);

            return $condition;
        });
    }
}
