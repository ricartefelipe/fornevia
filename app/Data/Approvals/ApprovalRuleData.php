<?php

declare(strict_types=1);

namespace App\Data\Approvals;

use App\Domain\Shared\Money;

/**
 * Dados de uma regra de aprovação (RF-050) vindos do formulário do
 * administrador da empresa. Critério nulo não restringe. Os níveis seguem
 * a ordem da lista: o primeiro é o nível 1, e assim por diante.
 *
 * Identificadores (centro de custo, unidade, categoria, aprovadores) e o
 * perfil do solicitante são entrada não confiável, conferidos pela Action.
 */
final readonly class ApprovalRuleData
{
    /**
     * @param  list<ApprovalRuleLevelData>  $levels
     */
    public function __construct(
        public string $name,
        public int $priority,
        public bool $isActive,
        public array $levels,
        public ?Money $minTotal = null,
        public ?string $costCenterId = null,
        public ?string $buyerUnitId = null,
        public ?string $categoryId = null,
        public ?string $requesterRoleKey = null,
    ) {}
}
