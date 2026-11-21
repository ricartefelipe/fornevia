<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Domain\Approvals\ApprovalStepView;
use App\Models\Approvals\OrderApprovalStep;

/**
 * Nível pendente de um pedido visto pelo ator da consulta: aprovadores
 * ainda elegíveis, titular em nome de quem o ator decidiria agora (ele
 * mesmo ou quem lhe delegou) e se o quórum ficou inalcançável (RN-052).
 */
final readonly class PendingStepState
{
    /**
     * @param  list<string>  $eligibleApproverIds  aprovadores do nível ainda elegíveis
     * @param  string|null  $principalId  titular da decisão do ator; null quando ele não pode decidir
     * @param  string|null  $unavailableReason  por que o ator não pode decidir (mensagem da regra)
     */
    public function __construct(
        public OrderApprovalStep $step,
        public ApprovalStepView $view,
        public string $requesterId,
        public array $eligibleApproverIds,
        public ?string $principalId,
        public ?string $unavailableReason,
        public bool $blocked,
    ) {}

    public function canDecide(): bool
    {
        return $this->principalId !== null;
    }

    /**
     * Titular representado quando o ator decide por delegação.
     */
    public function delegatedFrom(string $actorId): ?string
    {
        return $this->principalId !== null && $this->principalId !== $actorId ? $this->principalId : null;
    }

    /**
     * Aprovações válidas de titulares distintos (o solicitante não conta).
     */
    public function approvals(): int
    {
        return count($this->view->approvedPrincipals($this->requesterId));
    }

    /**
     * Aprovadores elegíveis que ainda não decidiram (sem o solicitante).
     *
     * @return list<string>
     */
    public function remainingApprovers(): array
    {
        return array_values(array_filter(
            $this->eligibleApproverIds,
            fn (string $id): bool => $id !== $this->requesterId && ! $this->view->hasDecided($id),
        ));
    }
}
