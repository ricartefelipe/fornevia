<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Models\Identity\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('senha-segura-123'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): self
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function platformAdmin(): self
    {
        return $this->state(fn (): array => ['is_platform_admin' => true]);
    }

    public function withTwoFactor(): self
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['codigo-recuperacao-1', 'codigo-recuperacao-2'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
