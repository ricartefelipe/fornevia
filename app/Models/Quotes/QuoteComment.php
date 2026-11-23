<?php

declare(strict_types=1);

namespace App\Models\Quotes;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Comentário compartilhado ou nota interna da equipe (append-only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $quote_id
 * @property string|null $quote_version_id
 * @property string $author_user_id
 * @property string|null $buyer_membership_id
 * @property string|null $staff_membership_id
 * @property string $author_name
 * @property \App\Enums\Quotes\QuoteCommentVisibility $visibility
 * @property string $body
 * @property \Carbon\CarbonImmutable $created_at
 */
class QuoteComment extends Model
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
            'visibility' => \App\Enums\Quotes\QuoteCommentVisibility::class,
        ];
    }
}
