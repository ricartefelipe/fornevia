<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Identity\User;
use App\Support\Auth\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Perfis privilegiados (gestão de equipe, crédito, financeiro, integrações,
 * organização, assinatura) e administradores da plataforma precisam de
 * segundo fator confirmado.
 */
final readonly class RequireTwoFactor
{
    public function __construct(private Access $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! (bool) config('security.two_factor.enforce', true) || $user->hasConfirmedTwoFactor()) {
            return $next($request);
        }

        $privileged = $user->is_platform_admin
            || ($this->access->membershipOrNull()?->role->isPrivileged() ?? false);

        if (! $privileged) {
            return $next($request);
        }

        // Bloqueio sempre interrompe o pipeline: o middleware persistente do
        // Livewire só respeita redirects e exceções, não outras respostas.
        if ($request->expectsJson()) {
            abort(403, 'Ative a autenticação em dois fatores.');
        }

        return redirect()->route('profile.security')
            ->with('warning', 'Seu perfil exige autenticação em dois fatores. Ative-a para continuar.');
    }
}
