<?php

declare(strict_types=1);

namespace App\Data\Approvals;

/**
 * Nível de uma regra de aprovação informado pelo administrador da empresa.
 * Os identificadores dos aprovadores são entrada não confiável: a Action
 * confere formato, empresa e elegibilidade.
 */
final readonly class ApprovalRuleLevelData
{
    /**
     * @param  list<string>  $approverIds  associações escolhidas como aprovadoras
     */
    public function __construct(
        public int $requiredApprovals,
        public array $approverIds,
    ) {}
}
