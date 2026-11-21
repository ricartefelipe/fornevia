<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Identity\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Cadastro de identidade. A criação de organização (tenant) ocorre depois,
 * no onboarding, ou pelo aceite de um convite.
 */
final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        /** @var array{name: string, email: string, password: string} $data */
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
        ])->validate();

        return User::query()->create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
        ]);
    }
}
