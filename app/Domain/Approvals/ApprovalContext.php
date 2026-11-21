<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Shared\Money;

/**
 * Dados do pedido usados para escolher a regra de aprovação (RF-050).
 */
final readonly class ApprovalContext
{
    /**
     * @param  list<string>  $categoryIds  categorias dos produtos do pedido
     */
    public function __construct(
        public Money $total,
        public ?string $costCenterId,
        public ?string $buyerUnitId,
        public array $categoryIds,
        public string $requesterRoleKey,
    ) {}
}
