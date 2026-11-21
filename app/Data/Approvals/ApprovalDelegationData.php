<?php

declare(strict_types=1);

namespace App\Data\Approvals;

/**
 * Delegação de aprovações pedida na tela (RN-053): titular, delegado,
 * primeiro e último dia (Y-m-d, no fuso do negócio, inclusive) e motivo.
 * Os identificadores são entrada não confiável.
 */
final readonly class ApprovalDelegationData
{
    public function __construct(
        public string $delegatorMembershipId,
        public string $delegateMembershipId,
        public string $firstDay,
        public string $lastDay,
        public string $reason,
    ) {}
}
