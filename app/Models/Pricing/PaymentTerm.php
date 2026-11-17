<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Condição de pagamento do distribuidor; requires_credit indica venda a prazo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property list<int> $installment_days
 * @property bool $requires_credit
 * @property bool $is_active
 */
class PaymentTerm extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'installment_days' => 'array',
            'requires_credit' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
