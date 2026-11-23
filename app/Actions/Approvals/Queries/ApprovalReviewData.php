<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Data\Approvals\ReviewedApproval;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Models\Ordering\Order;
use Carbon\CarbonImmutable;

/**
 * Detalhe do pedido para quem aprova: pedido (itens e totais retratados),
 * níveis, situação do nível pendente para o ator, candidatos à
 * reatribuição e histórico.
 */
final readonly class ApprovalReviewData
{
    /**
     * @param  list<StepReview>  $steps
     * @param  array<string, string>  $candidates  associação => nome, para reatribuir o nível bloqueado
     * @param  list<string>  $suggestedApprovers  aprovadores elegíveis do nível que ainda não decidiram
     * @param  list<array{id: string, label: string, at: CarbonImmutable, actor: string|null, details: list<string>}>  $history
     */
    public function __construct(
        public Order $order,
        public ?string $requesterName,
        public ?string $costCenterName,
        public ?string $unitName,
        public array $steps,
        public ?PendingStepState $current,
        public ?string $principalName,
        public bool $canReassign,
        public array $candidates,
        public array $suggestedApprovers,
        public array $history,
    ) {}

    public function awaitingApproval(): bool
    {
        return $this->current !== null;
    }

    public function canDecide(): bool
    {
        return $this->current?->canDecide() ?? false;
    }

    public function blocked(): bool
    {
        return $this->current !== null && $this->current->blocked;
    }

    /**
     * Níveis vigentes (sem os retratos substituídos na reavaliação).
     */
    public function levelCount(): int
    {
        return count(array_filter($this->steps, static fn (StepReview $s): bool => $s->step->status !== ApprovalStepStatus::Superseded));
    }

    /**
     * Versão exibida: o nível do formulário de decisão e reatribuição
     * ($current, a mesma leitura que define o que a tela oferece) e a
     * impressão do pedido exibido. A decisão e a reatribuição só valem se o
     * pedido ainda estiver nessa versão.
     */
    public function reviewed(): ReviewedApproval
    {
        return ReviewedApproval::of($this->order, $this->current?->step);
    }
}
