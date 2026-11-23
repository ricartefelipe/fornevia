<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Models\Identity\Membership;
use App\Models\Identity\User;

/**
 * Nomes exibidos nas telas de aprovação: usuários das associações da
 * empresa (solicitantes, aprovadores, delegados). A RLS restringe as
 * associações às da empresa do contexto.
 */
final class MemberNames
{
    /**
     * @param  list<string|null>  $membershipIds
     * @return array<string, string> associação => nome
     */
    public static function ofMemberships(array $membershipIds): array
    {
        $ids = array_values(array_unique(array_filter($membershipIds, is_string(...))));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $users */
        $users = Membership::query()->whereKey($ids)->pluck('user_id', 'id')->all();
        $names = self::ofUsers(array_values($users));
        $result = [];

        foreach ($users as $membershipId => $userId) {
            $result[(string) $membershipId] = $names[$userId] ?? 'Usuário sem nome';
        }

        return $result;
    }

    /**
     * @param  list<string|null>  $userIds
     * @return array<string, string> usuário => nome
     */
    public static function ofUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds, is_string(...))));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $names;
    }
}
