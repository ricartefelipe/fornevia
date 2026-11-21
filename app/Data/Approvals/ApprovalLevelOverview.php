<?php

declare(strict_types=1);

namespace App\Data\Approvals;

/**
 * Nível de uma regra na listagem: quórum, aprovadores e quantos deles ainda
 * podem aprovar hoje (RN-052).
 */
final readonly class ApprovalLevelOverview
{
    /**
     * @param  list<string>  $approverNames
     */
    public function __construct(
        public int $number,
        public int $requiredApprovals,
        public array $approverNames,
        public int $eligibleCount,
    ) {}

    /**
     * Sem aprovadores com acesso suficientes para o quórum: pedidos que caírem
     * neste nível ficam bloqueados até a reatribuição pela administração.
     */
    public function isShortOfApprovers(): bool
    {
        return $this->eligibleCount < $this->requiredApprovals;
    }

    public function summary(): string
    {
        $total = count($this->approverNames);

        return "{$this->requiredApprovals} de {$total} ".($total === 1 ? 'aprovador' : 'aprovadores');
    }
}
