<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\Identity\OrganizationStatus;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Suspende ou reativa o acesso de um distribuidor (relação com a plataforma).
 * Suspensão bloqueia todos os contextos do tenant na requisição seguinte.
 */
final class ChangeOrganizationStatus
{
    public function handle(User $actor, Organization $organization, OrganizationStatus $status, string $reason): Organization
    {
        if (! $actor->is_platform_admin) {
            throw new AuthorizationException('Somente administradores da plataforma alteram a situação de organizações.');
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw new InvalidArgumentException('Informe a justificativa.');
        }

        return DB::transaction(function () use ($actor, $organization, $status, $reason): Organization {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $previous = $locked->status;
            $locked->forceFill(['status' => $status])->save();

            DB::table('platform_audit_logs')->insert([
                'id' => (string) Str::uuid7(),
                'actor_user_id' => $actor->id,
                'organization_id' => $locked->id,
                'action' => 'organization.status_changed',
                'data' => json_encode(['from' => $previous->value, 'to' => $status->value], JSON_THROW_ON_ERROR),
                'reason' => trim($reason),
                'correlation_id' => Context::get('correlation_id'),
                'created_at' => now(),
            ]);

            return $locked;
        });
    }
}
