<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Clock\Clock;
use App\Support\Clock\SystemClock;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contexto e transação de tenant: escopo de requisição/job, nunca singleton.
        $this->app->scoped(CurrentContext::class);
        $this->app->scoped(TenantDatabase::class);

        $this->app->singleton(Clock::class, SystemClock::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Tipo citext (extensão provisionada no banco) para e-mails e slugs.
        PostgresGrammar::macro('typeCitext', fn (): string => 'citext');

        // Falhas explícitas para lazy loading (N+1), atributos inexistentes e
        // atribuições descartadas silenciosamente fora de produção.
        Model::shouldBeStrict(! $this->app->isProduction());

        Password::defaults(static function (): Password {
            $rule = Password::min(12)->letters()->numbers();

            return config('security.passwords.breach_check') ? $rule->uncompromised(3) : $rule;
        });
    }
}
