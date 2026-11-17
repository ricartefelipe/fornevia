<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Identity\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  User  $user
     * @param  array<string, mixed>  $input
     */
    public function reset($user, array $input): void
    {
        /** @var array{password: string} $data */
        $data = Validator::make($input, ['password' => $this->passwordRules()])->validate();

        $user->forceFill(['password' => $data['password']])->save();
    }
}
