<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

/**
 * Nível de aprovação de um pedido, com as decisões válidas registradas.
 */
final readonly class ApprovalStepView
{
    /**
     * @param  list<string>  $approverIds  aprovadores retratados na submissão
     * @param  list<array{actor: string, principal: string, approved: bool}>  $decisions  decisões válidas
     */
    public function __construct(
        public string $id,
        public int $levelNumber,
        public int $requiredApprovals,
        public array $approverIds,
        public array $decisions,
    ) {}

    /**
     * Titulares distintos, aprovadores do nível, que aprovaram (o
     * solicitante nunca conta, RN-051).
     *
     * @return list<string>
     */
    public function approvedPrincipals(string $requesterId): array
    {
        $principals = [];

        foreach ($this->decisions as $decision) {
            if ($decision['approved'] && in_array($decision['principal'], $this->approverIds, true)
                && $decision['principal'] !== $requesterId && $decision['actor'] !== $requesterId) {
                $principals[$decision['principal']] = true;
            }
        }

        return array_keys($principals);
    }

    public function isRejected(): bool
    {
        foreach ($this->decisions as $decision) {
            if (! $decision['approved']) {
                return true;
            }
        }

        return false;
    }

    public function isApproved(string $requesterId): bool
    {
        return ! $this->isRejected() && count($this->approvedPrincipals($requesterId)) >= $this->requiredApprovals;
    }

    public function hasDecided(string $membershipId): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision['actor'] === $membershipId || $decision['principal'] === $membershipId) {
                return true;
            }
        }

        return false;
    }
}
