<?php

declare(strict_types=1);

namespace App\Models\Quotes;

use App\Domain\Quotes\QuoteLifecycle;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Versão da proposta da equipe: conteúdo imutável desde a inserção; só a situação muda.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $quote_id
 * @property int|null $version_number
 * @property QuoteVersionStatus $status
 * @property string $created_by_user_id
 * @property string $created_by_membership_id
 * @property string $payment_term_id
 * @property array<string, mixed> $payment_term
 * @property \Carbon\CarbonImmutable $valid_until
 * @property \Carbon\CarbonImmutable|null $stock_hold_until
 * @property int $item_count
 * @property \App\Domain\Shared\Money $subtotal_cents
 * @property \App\Domain\Shared\Money $discount_cents
 * @property \App\Domain\Shared\Money $total_cents
 * @property int $price_version
 * @property string|null $notes
 * @property string $content_fingerprint
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property string|null $sent_by_membership_id
 * @property \Carbon\CarbonImmutable|null $accepted_at
 * @property string|null $accepted_by_user_id
 * @property string|null $accepted_by_membership_id
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property \App\Enums\Quotes\StockHoldStatus $stock_hold_status
 * @property \Carbon\CarbonImmutable|null $stock_hold_ended_at
 * @property \App\Enums\Quotes\StockHoldEndReason|null $stock_hold_end_reason
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class QuoteVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(static function (self $version): void {
            if ($version->isDirty('status')) {
                QuoteLifecycle::assertVersionMove(QuoteVersionStatus::from((string) $version->getRawOriginal('status')), $version->status);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'status' => QuoteVersionStatus::class,
            'payment_term' => 'array',
            'valid_until' => 'immutable_datetime',
            'stock_hold_until' => 'immutable_datetime',
            'item_count' => 'integer',
            'subtotal_cents' => \App\Support\Database\Casts\AsMoney::class,
            'discount_cents' => \App\Support\Database\Casts\AsMoney::class,
            'total_cents' => \App\Support\Database\Casts\AsMoney::class,
            'price_version' => 'integer',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'stock_hold_status' => \App\Enums\Quotes\StockHoldStatus::class,
            'stock_hold_ended_at' => 'immutable_datetime',
            'stock_hold_end_reason' => \App\Enums\Quotes\StockHoldEndReason::class,
        ];
    }

    /**
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return HasOne<QuoteCommercialReview, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(QuoteCommercialReview::class);
    }
}
