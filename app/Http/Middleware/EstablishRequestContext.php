<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Identity\MembershipStatus;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Support\Auth\Access;
use App\Support\Tenancy\ContextKind;
use App\Support\Tenancy\Exceptions\InvalidExecutionContext;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\RollbackWithResponse;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Estabelece o contexto de execução das requisições web autenticadas.
 *
 * Executa antes do route model binding (prioridade configurada em
 * bootstrap/app.php). O contexto guardado na sessão é revalidado no banco
 * em toda requisição: a associação precisa existir, pertencer ao usuário,
 * estar ativa e a organização precisa permitir acesso. Assim, revogações
 * têm efeito na requisição seguinte.
 *
 * A requisição inteira roda em uma transação curta com o contexto local;
 * se a resposta resultar de exceção, a transação é desfeita.
 */
final readonly class EstablishRequestContext
{
    public const string SESSION_KEY = 'fornevia.context';

    public function __construct(
        private TenantDatabase $db,
        private Access $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->isDisabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('status', 'Sua conta está desativada.');
        }

        $context = $this->contextFromSession($request, $user);

        try {
            return $this->db->run($context, function () use ($request, $next, $user, $context): Response {
                if ($context->kind !== ContextKind::User && ! $this->bindMembership($user, $context)) {
                    $request->session()->forget(self::SESSION_KEY);

                    return redirect()->route('contexts.index')
                        ->with('status', 'O acesso a este contexto não está mais disponível.');
                }

                $response = $next($request);

                if ($this->carriesException($response)) {
                    throw new RollbackWithResponse($response);
                }

                return $response;
            });
        } catch (RollbackWithResponse $rollback) {
            return $rollback->response;
        }
    }

    /**
     * @return array{membership_id: string, tenant_id: string, kind: string, buyer_company_id: string|null}
     */
    public static function sessionPayload(Membership $membership): array
    {
        return [
            'membership_id' => $membership->id,
            'tenant_id' => $membership->tenant_id,
            'kind' => $membership->kind->contextKind()->value,
            'buyer_company_id' => $membership->buyer_company_id,
        ];
    }

    private function contextFromSession(Request $request, User $user): ExecutionContext
    {
        $stored = $request->session()->get(self::SESSION_KEY);

        if (! is_array($stored)) {
            return ExecutionContext::user($user->id);
        }

        try {
            return match ($stored['kind'] ?? null) {
                ContextKind::Staff->value => ExecutionContext::staff($user->id, (string) $stored['tenant_id'], (string) $stored['membership_id']),
                ContextKind::Buyer->value => ExecutionContext::buyer(
                    $user->id,
                    (string) $stored['tenant_id'],
                    (string) $stored['buyer_company_id'],
                    (string) $stored['membership_id'],
                ),
                default => throw new InvalidExecutionContext('Tipo de contexto inválido na sessão.'),
            };
        } catch (InvalidExecutionContext) {
            $request->session()->forget(self::SESSION_KEY);

            return ExecutionContext::user($user->id);
        }
    }

    private function bindMembership(User $user, ExecutionContext $context): bool
    {
        $membership = Membership::query()
            ->with(['role', 'tenant', 'buyerCompany'])
            ->whereKey($context->membershipId)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->first();

        if ($membership === null
            || $membership->kind->contextKind() !== $context->kind
            || $membership->buyer_company_id !== $context->buyerCompanyId
            || ! $membership->tenant->allowsAccess()) {
            return false;
        }

        $this->access->bindMembership($membership);

        return true;
    }

    private function carriesException(Response $response): bool
    {
        return ($response instanceof IlluminateResponse
                || $response instanceof JsonResponse
                || $response instanceof RedirectResponse)
            && $response->exception !== null;
    }
}
