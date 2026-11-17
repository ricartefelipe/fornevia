<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Models\Identity\Organization;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'legal_name' => $name.' Ltda',
            'trade_name' => $name,
            'document' => BrazilianDocuments::cnpj(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'email' => fake()->companyEmail(),
        ];
    }
}
