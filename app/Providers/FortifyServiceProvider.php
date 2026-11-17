<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(static fn () => view('auth.login'));
        Fortify::registerView(static fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(static fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(static fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(static fn () => view('auth.verify-email'));
        Fortify::twoFactorChallengeView(static fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(static fn () => view('auth.confirm-password'));

        RateLimiter::for('login', static function (Request $request): Limit {
            $email = Str::lower((string) $request->input(Fortify::username()));

            return Limit::perMinute(5)->by(Str::transliterate($email.'|'.$request->ip()));
        });

        RateLimiter::for('two-factor', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->session()->get('login.id').'|'.$request->ip()));
    }
}
