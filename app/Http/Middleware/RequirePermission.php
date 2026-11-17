<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Identity\Permission;
use App\Support\Auth\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequirePermission
{
    public function __construct(private Access $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        foreach ($permissions as $value) {
            $this->access->authorize(Permission::from($value));
        }

        return $next($request);
    }
}
