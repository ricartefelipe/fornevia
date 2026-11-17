<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Services\Audit\AuditRecorder;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Revoga uma associação. O efeito é imediato: o middleware de contexto
 * valida a associação ativa no banco a cada requisição.
 */
final readonly class RevokeMembership
{
    public function __construct(
        private AuthorizesMemberManagement $authorization,
        private OwnershipGuard $ownership,
        private AuditRecorder $audit,
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    public function handle(Membership $membership, string $reason): Membership
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Informe a justificativa da revogação.');
        }

        $this->authorization->authorize($membership->kind, $membership->buyer_company_id);

        return DB::transaction(function () use ($membership, $reason): Membership {
            $locked = Membership::query()->with('role')->lockForUpdate()->findOrFail($membership->id);

            if (! $locked->isActive()) {
                return $locked;
            }

            if ($locked->kind === MembershipKind::Staff && $locked->role->key === SystemRole::Owner->value) {
                $this->authorization->authorizeRole($locked->role);
                $this->ownership->assertAnotherActiveOwner($locked);
            }

            $locked->forceFill([
                'status' => MembershipStatus::Revoked,
                'revoked_at' => $this->clock->now(),
                'revoked_by_user_id' => $this->context->require()->userId,
                'revoke_reason' => $reason,
            ])->save();

            $this->audit->record('membership.revoked', $locked, [
                'user_id' => $locked->user_id,
                'kind' => $locked->kind->value,
                'role' => $locked->role->key,
            ], $reason);

            return $locked;
        });
    }
}
