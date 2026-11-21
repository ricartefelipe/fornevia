<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Identity\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * O painel do Horizon é protegido em todos os ambientes, inclusive local
     * (o padrão do pacote libera o ambiente local sem verificação).
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(static fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (?User $user = null): bool {
            if ($user === null || ! $user->hasConfirmedTwoFactor() || $user->isDisabled()) {
                return false;
            }

            /** @var list<string> $allowed */
            $allowed = config('horizon.allowed_emails', []);

            return $user->is_platform_admin || in_array(mb_strtolower($user->email), array_map('mb_strtolower', $allowed), true);
        });
    }
}
