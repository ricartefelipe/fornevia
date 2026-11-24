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
 * Fatura comercial (não fiscal) de um pedido (RF-100, ADR-028).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $order_id
 * @property string $number
 * @property \App\Enums\Finance\InvoiceStatus $status
 * @property Money $items_cents
 * @property Money $freight_cents
 * @property Money $total_cents
 * @property Money $paid_cents
 * @property Money $credited_cents
 * @property Money $adjusted_cents
 * @property array<string, mixed> $payment_term
 * @property \Carbon\CarbonImmutable $issue_date
 * @property string|null $issued_by_user_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read \App\Models\Ordering\Order $order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ReceivableInstallment> $installments
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /** Valores iniciais iguais aos padrões do banco. */
    protected $attributes = ['paid_cents' => 0, 'credited_cents' => 0, 'adjusted_cents' => 0];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => \App\Enums\Finance\InvoiceStatus::class,
            'items_cents' => AsMoney::class,
            'freight_cents' => AsMoney::class,
            'total_cents' => AsMoney::class,
            'paid_cents' => AsMoney::class,
            'credited_cents' => AsMoney::class,
            'adjusted_cents' => AsMoney::class,
            'payment_term' => 'array',
            'issue_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<ReceivableInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(ReceivableInstallment::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<\App\Models\Ordering\Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Ordering\Order::class);
    }
}
