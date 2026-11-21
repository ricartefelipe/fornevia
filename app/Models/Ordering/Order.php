<?php

declare(strict_types=1);

namespace App\Models\Ordering;

use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Enums\Ordering\FinancialStatus;
use App\Enums\Ordering\FulfillmentStatus;
use App\Enums\Ordering\OrderSource;
use App\Models\Buyers\BuyerCompany;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido com snapshots de preços, condições e endereços, e estados separados (comercial, aprovação, logístico e financeiro).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $buyer_company_id
 * @property string|null $buyer_unit_id
 * @property string|null $cost_center_id
 * @property string $payment_term_id
 * @property string $placed_by_user_id
 * @property string|null $placed_by_membership_id
 * @property string|null $sales_rep_membership_id
 * @property OrderSource $source
 * @property string|null $source_cart_id
 * @property string|null $source_quote_version_id
 * @property string|null $purchase_order_reference
 * @property array<string, mixed> $shipping_address
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed> $payment_term
 * @property \App\Domain\Shared\Money $subtotal_cents
 * @property \App\Domain\Shared\Money $discount_cents
 * @property \App\Domain\Shared\Money $freight_cents
 * @property \App\Domain\Shared\Money $total_cents
 * @property \App\Domain\Shared\Money $credit_committed_cents
 * @property CommercialStatus $commercial_status
 * @property ApprovalStatus $approval_status
 * @property FulfillmentStatus $fulfillment_status
 * @property FinancialStatus $financial_status
 * @property \Carbon\CarbonImmutable|null $confirmed_at
 * @property \Carbon\CarbonImmutable|null $cancelled_at
 * @property string|null $approval_rule_id
 * @property string|null $approval_rule_name
 * @property string|null $approval_fingerprint
 * @property \Carbon\CarbonImmutable|null $submitted_at
 * @property \Carbon\CarbonImmutable|null $approved_at
 * @property string|null $confirmation_issue
 * @property string|null $cancel_reason
 * @property string|null $notes
 * @property \Carbon\CarbonImmutable $created_at
 */
class Order extends Model
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
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'payment_term' => 'array',
            'subtotal_cents' => AsMoney::class,
            'discount_cents' => AsMoney::class,
            'freight_cents' => AsMoney::class,
            'total_cents' => AsMoney::class,
            'credit_committed_cents' => AsMoney::class,
            'source' => OrderSource::class,
            'commercial_status' => CommercialStatus::class,
            'approval_status' => ApprovalStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'financial_status' => FinancialStatus::class,
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Níveis vigentes da aprovação (sem os retratos substituídos na
     * reavaliação).
     *
     * @return HasMany<\App\Models\Approvals\OrderApprovalStep, $this>
     */
    public function approvalSteps(): HasMany
    {
        return $this->hasMany(\App\Models\Approvals\OrderApprovalStep::class)
            ->where('status', '<>', \App\Enums\Approvals\ApprovalStepStatus::Superseded->value)
            ->orderBy('level_number');
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /**
     * @return BelongsTo<BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(BuyerCompany::class);
    }
}
