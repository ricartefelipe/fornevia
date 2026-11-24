<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Domain\Shared\Money;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pagamento de uma parcela (RF-100, RF-101): manual ou pelo gateway; só o confirmado quita (RN-083).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $installment_id
 * @property Money $amount_cents
 * @property Money $fee_cents
 * @property \App\Enums\Finance\PaymentMethod $method
 * @property \App\Enums\Finance\PaymentSource $source
 * @property \App\Enums\Finance\PaymentGatewayKind|null $gateway
 * @property string|null $gateway_charge_id
 * @property string|null $checkout_url
 * @property \App\Enums\Finance\PaymentStatus $status
 * @property Money $refunded_cents
 * @property Money $refund_pending_cents
 * @property \Carbon\CarbonImmutable|null $confirmed_at
 * @property string|null $failure_reason
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read ReceivableInstallment $installment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PaymentRefund> $refunds
 */
class Payment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /** Valores iniciais iguais aos padrões do banco. */
    protected $attributes = ['fee_cents' => 0, 'refunded_cents' => 0, 'refund_pending_cents' => 0];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => AsMoney::class,
            'fee_cents' => AsMoney::class,
            'method' => \App\Enums\Finance\PaymentMethod::class,
            'source' => \App\Enums\Finance\PaymentSource::class,
            'gateway' => \App\Enums\Finance\PaymentGatewayKind::class,
            'status' => \App\Enums\Finance\PaymentStatus::class,
            'refunded_cents' => AsMoney::class,
            'refund_pending_cents' => AsMoney::class,
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ReceivableInstallment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(ReceivableInstallment::class, 'installment_id');
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
