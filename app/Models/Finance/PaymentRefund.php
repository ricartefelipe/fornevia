<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Domain\Shared\Money;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Reembolso de um pagamento confirmado (RN-101).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $payment_id
 * @property Money $amount_cents
 * @property \App\Enums\Finance\RefundStatus $status
 * @property string $reason
 * @property string|null $gateway_refund_id
 * @property \Carbon\CarbonImmutable|null $verification_started_at
 * @property bool $gateway_reference_sent
 * @property \Carbon\CarbonImmutable|null $last_checked_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class PaymentRefund extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => AsMoney::class,
            'status' => \App\Enums\Finance\RefundStatus::class,
            'created_at' => 'immutable_datetime',
            'verification_started_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'gateway_reference_sent' => 'boolean',
        ];
    }
}
