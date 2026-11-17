<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Support\Http\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: static function (): void {
            Route::group([], base_path('routes/health.php'));
        },
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->encryptCookies();
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token', 'code', 'recovery_code']);

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(static fn (Throwable $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->render($e, $request)
            : null);
    })->create();
