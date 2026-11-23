<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O pedido mudou desde que a tela foi exibida (reavaliação com novo total
 * ou condições, outro nível em análise): a decisão ou a reatribuição não
 * vale para o que não foi visto (RN-054).
 */
final class ApprovalOutdated extends ConflictingState
{
    public function errorCode(): string
    {
        return 'approval_outdated';
    }
}
