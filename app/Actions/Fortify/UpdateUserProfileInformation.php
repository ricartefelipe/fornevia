<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Identity\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  User  $user
     * @param  array<string, mixed>  $input
     */
    public function update($user, array $input): void
    {
        /** @var array{name: string, email: string} $data */
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
        ])->validateWithBag('updateProfileInformation');

        $email = mb_strtolower($data['email']);

        if ($email !== $user->email) {
            $user->forceFill(['name' => $data['name'], 'email' => $email, 'email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();

            return;
        }

        $user->forceFill(['name' => $data['name']])->save();
    }
}
