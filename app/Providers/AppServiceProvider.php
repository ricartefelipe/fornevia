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
use App\Support\Observability\Metrics;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        // Jobs com falha entram nas métricas, por fila (RQ-121).
        Queue::failing(static fn (JobFailed $event) => app(Metrics::class)->jobFailed($event->job->getQueue()));

        // Links (e-mails de convite, redefinição de senha, verificação) usam a
        // URL configurada, nunca o cabeçalho Host da requisição.
        URL::forceRootUrl((string) config('app.url'));

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Redefinição de senha encerra todas as sessões persistidas do usuário
        // (o Fortify já rotaciona o token de "lembrar-me").
        Event::listen(PasswordReset::class, static function (PasswordReset $event): void {
            $id = $event->user->getAuthIdentifier();

            if (is_string($id)) {
                DB::table('sessions')->where('user_id', $id)->delete();
            }
        });

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
            Authenticate::class,
            EnsureEmailIsVerified::class,
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
