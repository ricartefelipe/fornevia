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
 * Parcela a receber da fatura (RF-100): valor, ajustes, pago e creditado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $invoice_id
 * @property int $sequence
 * @property \Carbon\CarbonImmutable $due_date
 * @property Money $amount_cents
 * @property Money $adjusted_cents
 * @property Money $paid_cents
 * @property Money $credited_cents
 * @property \App\Enums\Finance\InstallmentStatus $status
 * @property-read Invoice $invoice
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 */
class ReceivableInstallment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /** Valores iniciais iguais aos padrões do banco. */
    protected $attributes = ['adjusted_cents' => 0, 'paid_cents' => 0, 'credited_cents' => 0];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'immutable_date',
            'amount_cents' => AsMoney::class,
            'adjusted_cents' => AsMoney::class,
            'paid_cents' => AsMoney::class,
            'credited_cents' => AsMoney::class,
            'status' => \App\Enums\Finance\InstallmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'installment_id')->orderBy('created_at');
    }
}
