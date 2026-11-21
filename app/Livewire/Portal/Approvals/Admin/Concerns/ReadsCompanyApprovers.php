<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Approvals\Admin\Concerns;

use App\Data\Approvals\ApproverOption;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Models\Identity\Membership;
use Livewire\Component;

/**
 * Usuários da empresa compradora para as telas de regras e delegações e
 * associação das mensagens de regra de negócio aos campos do formulário.
 *
 * @mixin Component
 */
trait ReadsCompanyApprovers
{
    /**
     * Todas as associações da empresa (inclusive revogadas, para exibir nomes
     * em regras e delegações antigas), ordenadas pelo nome.
     *
     * @return array<string, ApproverOption> membership_id => usuário
     */
    protected function companyMembers(string $companyId): array
    {
        $members = Membership::query()
            ->with(['user', 'role'])
            ->where('buyer_company_id', $companyId)
            ->get()
            ->sortBy(static fn (Membership $membership): string => mb_strtolower($membership->user->name).'|'.$membership->id);

        $options = [];

        foreach ($members as $membership) {
            $options[$membership->id] = new ApproverOption(
                $membership->id,
                $membership->user->name,
                $membership->user->email,
                $membership->role->name,
                $membership->isActive() && $membership->role->grants(Permission::PortalApprovals),
            );
        }

        return $options;
    }

    /**
     * @param  array<string, ApproverOption>  $members
     * @return array<string, ApproverOption>
     */
    protected static function eligibleOnly(array $members): array
    {
        return array_filter($members, static fn (ApproverOption $option): bool => $option->eligible);
    }

    /**
     * Associa a mensagem da regra de negócio ao campo do formulário.
     *
     * @param  array<string, string>  $fields  campo da regra => propriedade
     */
    protected function addViolation(BusinessRuleViolation $violation, array $fields, string $fallback): void
    {
        $field = $violation->details()['field'] ?? null;

        $this->addError(is_string($field) && isset($fields[$field]) ? $fields[$field] : $fallback, $violation->getMessage());
    }
}
