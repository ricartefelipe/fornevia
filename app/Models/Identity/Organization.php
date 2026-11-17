<?php

declare(strict_types=1);

namespace App\Models\Identity;

use App\Enums\Identity\OrganizationStatus;
use Database\Factories\Identity\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Distribuidor contratante: o tenant.
 *
 * @property string $id
 * @property string $legal_name
 * @property string $trade_name
 * @property string $document
 * @property string $slug
 * @property OrganizationStatus $status
 * @property string|null $email
 * @property string|null $phone
 * @property array<string, mixed> $settings
 */
#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['legal_name', 'trade_name', 'document', 'slug', 'email', 'phone'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'trial',
        'settings' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'settings' => 'array',
        ];
    }

    public function allowsAccess(): bool
    {
        return $this->status->allowsAccess();
    }
}
