<?php

declare(strict_types=1);

namespace App\Data\Approvals;

/**
 * Usuário da empresa compradora exibido nas telas de regras e delegações.
 * Elegível = associação ativa com permissão de aprovar compras (a mesma
 * definição de ApprovalEligibility).
 */
final readonly class ApproverOption
{
    public function __construct(
        public string $membershipId,
        public string $name,
        public string $email,
        public string $roleName,
        public bool $eligible,
    ) {}

    public function label(): string
    {
        return $this->name.' ('.$this->roleName.')';
    }
}
