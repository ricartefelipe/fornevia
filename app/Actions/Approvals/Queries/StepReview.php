<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Enums\Approvals\ApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use Carbon\CarbonImmutable;

/**
 * Nível de aprovação do pedido no detalhe: quórum, aprovadores retratados
 * (com a situação de cada um) e decisões, inclusive as invalidadas.
 */
final readonly class StepReview
{
    /**
     * @param  list<array{id: string, name: string, situation: string, color: string}>  $approvers
     * @param  list<array{id: string, actor: string, principal: string|null, decision: ApprovalDecision, comment: string|null, at: CarbonImmutable, invalidatedAt: CarbonImmutable|null}>  $decisions
     */
    public function __construct(
        public OrderApprovalStep $step,
        public int $approvals,
        public bool $current,
        public bool $blocked,
        public array $approvers,
        public array $decisions,
    ) {}
}
