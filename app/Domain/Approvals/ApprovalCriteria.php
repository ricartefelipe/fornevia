<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Shared\Money;

/**
 * Critérios de uma regra de aprovação. Critério ausente (null) não
 * restringe; os presentes precisam ser todos atendidos.
 */
final readonly class ApprovalCriteria
{
    public function __construct(
        public ?Money $minTotal = null,
        public ?string $costCenterId = null,
        public ?string $buyerUnitId = null,
        public ?string $categoryId = null,
        public ?string $requesterRoleKey = null,
    ) {}

    public function matches(ApprovalContext $context): bool
    {
        return ($this->minTotal === null || $context->total->greaterThanOrEqual($this->minTotal))
            && ($this->costCenterId === null || $this->costCenterId === $context->costCenterId)
            && ($this->buyerUnitId === null || $this->buyerUnitId === $context->buyerUnitId)
            && ($this->categoryId === null || in_array($this->categoryId, $context->categoryIds, true))
            && ($this->requesterRoleKey === null || $this->requesterRoleKey === $context->requesterRoleKey);
    }
}
