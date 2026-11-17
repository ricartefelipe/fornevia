<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Domain\Identity\Exceptions\ContextUnavailable;
use App\Enums\Identity\MembershipStatus;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Str;

/**
 * Troca de contexto. O identificador recebido é entrada não confiável:
 * a associação é procurada no contexto do próprio usuário (a RLS só expõe
 * as associações dele) e precisa estar ativa em organização com acesso.
 */
final readonly class SelectContext
{
    public function __construct(
        private TenantDatabase $db,
        private Clock $clock,
    ) {}

    public function handle(User $user, string $membershipId): Membership
    {
        if (! Str::isUuid($membershipId) || $user->isDisabled()) {
            throw new ContextUnavailable('Contexto indisponível.');
        }

        $membership = $this->db->run(ExecutionContext::user($user->id), static fn (): ?Membership => Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->whereKey($membershipId)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->first());

        if ($membership === null || ! $membership->tenant->allowsAccess()) {
            throw new ContextUnavailable('Contexto indisponível.');
        }

        $this->db->run($membership->executionContext(), function () use ($membership, $user): void {
            Membership::query()->whereKey($membership->id)->update(['last_used_at' => $this->clock->now()]);
            $user->forceFill(['last_membership_id' => $membership->id])->save();
        });

        return $membership;
    }
}
