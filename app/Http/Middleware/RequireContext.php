<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige contexto de tenant: "staff", "buyer" ou "any".
 */
final readonly class RequireContext
{
    public function __construct(private Access $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $kind = 'any'): Response
    {
        if (! $this->access->hasMembership()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Selecione um contexto.'], 409)
                : redirect()->guest(route('contexts.index'));
        }

        $allowed = match ($kind) {
            'staff' => $this->access->isStaff(),
            'buyer' => $this->access->isBuyer(),
            default => true,
        };

        abort_unless($allowed, 403, 'Esta área não está disponível no contexto atual.');

        return $next($request);
    }
}
