<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Identity\Permission;
use App\Http\Middleware\RequireContext;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\MorphMap;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Clock\SystemClock;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contexto, transação de tenant e permissões: escopo de requisição/job,
        // nunca singleton reaproveitado entre execuções.
        $this->app->scoped(CurrentContext::class);
        $this->app->scoped(TenantDatabase::class);
        $this->app->scoped(Access::class);

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

        // Tipos polimórficos estáveis (auditoria, anexos); classes fora do mapa falham.
        Relation::enforceMorphMap(MorphMap::MAP);

        // As ações de componentes Livewire reaplicam no servidor as mesmas
        // exigências da rota que renderizou a página.
        Livewire::addPersistentMiddleware([
            RequireContext::class,
            RequirePermission::class,
            RequireTwoFactor::class,
            RequirePlatformAdmin::class,
        ]);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, static fn (?Authenticatable $user = null): bool => app(Access::class)->can($permission));
        }

        RateLimiter::for('invitations', static fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));

        Password::defaults(static function (): Password {
            $rule = Password::min(12)->letters()->numbers();

            return config('security.passwords.breach_check') ? $rule->uncompromised(3) : $rule;
        });
    }
}
