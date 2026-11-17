<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Models\Identity\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Exige contexto de tenant ativo (TenantDatabase::run).
 *
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('FL##??')),
            'name' => 'Filial '.fake()->city(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(['SP', 'RJ', 'MG', 'PR', 'SC', 'RS', 'BA', 'GO']),
            'is_headquarters' => false,
            'is_active' => true,
        ];
    }
}
