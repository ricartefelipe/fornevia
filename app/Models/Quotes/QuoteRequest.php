<?php

declare(strict_types=1);

namespace App\Models\Quotes;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitação ou pedido de revisão do comprador (append-only, sem preço).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $quote_id
 * @property int $sequence
 * @property \App\Enums\Quotes\QuoteRequestKind $kind
 * @property string|null $responds_to_version_id
 * @property string|null $message
 * @property string $author_user_id
 * @property string $author_membership_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class QuoteRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** Registro append-only: apenas created_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'kind' => \App\Enums\Quotes\QuoteRequestKind::class,
        ];
    }

    /**
     * @return HasMany<QuoteRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteRequestItem::class)->orderBy('line_number');
    }
}
