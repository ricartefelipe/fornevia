<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Models\Buyers\CustomerGroup;
use App\Models\Identity\Membership;

/**
 * Opções das listas de seleção do cadastro de compradores, sempre lidas no
 * escopo do tenant ativo.
 */
final class BuyerOptions
{
    /**
     * Cor do selo de situação (componente x-ui.badge).
     */
    public static function statusColor(BuyerCompanyStatus $status): string
    {
        return match ($status) {
            BuyerCompanyStatus::Active => 'green',
            BuyerCompanyStatus::Blocked => 'red',
            BuyerCompanyStatus::Inactive => 'slate',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        $options = [];

        foreach (BuyerCompanyStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function customerGroups(): array
    {
        return CustomerGroup::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (CustomerGroup $group): array => [$group->id => "{$group->name} ({$group->code})"])
            ->all();
    }

    /**
     * Associações de equipe ativas. O representante atual, se revogado, é
     * incluído com indicação, para que a seleção reflita o cadastro.
     *
     * @return array<string, string>
     */
    public static function salesReps(?string $currentId = null): array
    {
        $members = Membership::query()
            ->with(['user', 'role'])
            ->where('kind', MembershipKind::Staff->value)
            ->where(static function ($query) use ($currentId): void {
                $query->where('status', MembershipStatus::Active->value);

                if ($currentId !== null && $currentId !== '') {
                    $query->orWhere('id', $currentId);
                }
            })
            ->get()
            ->sortBy(static fn (Membership $membership): string => mb_strtolower($membership->user->name));

        $options = [];

        foreach ($members as $membership) {
            $options[$membership->id] = $membership->user->name.' · '.$membership->role->name
                .($membership->isActive() ? '' : ' (acesso revogado)');
        }

        return $options;
    }
}
