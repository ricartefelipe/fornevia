<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Enums\Approvals\ApprovalDecision;
use Carbon\CarbonImmutable;

/**
 * Última decisão do ator (ou tomada em nome dele) em um pedido.
 */
final readonly class InboxDecision
{
    /**
     * @param  string|null  $actorName  quem decidiu em nome do ator, por delegação
     * @param  string|null  $principalName  titular em nome de quem o ator decidiu
     */
    public function __construct(
        public ApprovalDecision $decision,
        public int $level,
        public CarbonImmutable $at,
        public bool $invalidated,
        public ?string $actorName,
        public ?string $principalName,
        public ?string $comment,
    ) {}
}
