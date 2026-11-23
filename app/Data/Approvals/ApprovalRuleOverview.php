<?php

declare(strict_types=1);

namespace App\Data\Approvals;

/**
 * Regra de aprovação na listagem do administrador, com os critérios já
 * descritos em texto e os níveis resumidos.
 */
final readonly class ApprovalRuleOverview
{
    /**
     * @param  list<string>  $criteria  descrição de cada critério presente (vazio = todos os pedidos)
     * @param  list<ApprovalLevelOverview>  $levels
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $priority,
        public bool $isActive,
        public array $criteria,
        public array $levels,
    ) {}

    public function isShortOfApprovers(): bool
    {
        foreach ($this->levels as $level) {
            if ($level->isShortOfApprovers()) {
                return true;
            }
        }

        return false;
    }
}
