<?php

declare(strict_types=1);

namespace App\Models\Quotes;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cotação da empresa compradora (ADR-025).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $buyer_company_id
 * @property string $requested_by_user_id
 * @property string $requested_by_membership_id
 * @property string|null $sales_rep_membership_id
 * @property \App\Enums\Quotes\QuoteStatus $status
 * @property \Carbon\CarbonImmutable|null $needed_by
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property string|null $closed_by_user_id
 * @property \App\Enums\Quotes\QuoteCloseReason|null $close_reason_code
 * @property string|null $close_reason
 * @property \Carbon\CarbonImmutable $last_activity_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Quote extends Model
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
            'status' => \App\Enums\Quotes\QuoteStatus::class,
            'needed_by' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'close_reason_code' => \App\Enums\Quotes\QuoteCloseReason::class,
            'last_activity_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<QuoteVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<QuoteRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<QuoteComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(QuoteComment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<\App\Models\Buyers\BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Buyers\BuyerCompany::class);
    }
}
