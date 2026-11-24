<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Domain\Shared\Money;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lançamento financeiro somente inclusão (RN-100): correções por lançamento compensatório.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string|null $installment_id
 * @property string|null $payment_id
 * @property \App\Enums\Finance\FinanceEntryType $type
 * @property int $receivable_delta
 * @property Money $amount_cents
 * @property string|null $reason
 * @property string|null $actor_user_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class FinanceEntry extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => \App\Enums\Finance\FinanceEntryType::class,
            'receivable_delta' => 'integer',
            'amount_cents' => AsMoney::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
