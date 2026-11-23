<?php

declare(strict_types=1);

namespace App\Models\Quotes;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Avaliação comercial interna da versão (RN-041; ADR-020): só a equipe lê.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $quote_version_id
 * @property string $evaluated_by_user_id
 * @property string $evaluated_by_membership_id
 * @property int $author_limit_bps
 * @property int $max_discount_bps
 * @property bool $requires_approval
 * @property list<string> $violations
 * @property list<array<string, mixed>> $lines
 * @property string|null $justification
 * @property string|null $decision
 * @property string|null $decided_by_user_id
 * @property string|null $decided_by_membership_id
 * @property int|null $decider_limit_bps
 * @property string|null $decision_comment
 * @property \Carbon\CarbonImmutable|null $decided_at
 * @property bool $self_approved
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class QuoteCommercialReview extends Model
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
            'author_limit_bps' => 'integer',
            'max_discount_bps' => 'integer',
            'requires_approval' => 'boolean',
            'violations' => 'array',
            'lines' => 'array',
            'decider_limit_bps' => 'integer',
            'decided_at' => 'immutable_datetime',
            'self_approved' => 'boolean',
        ];
    }
}
