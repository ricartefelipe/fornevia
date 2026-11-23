<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Approvals\Inbox;

use App\Actions\Approvals\DecideOrderApproval;
use App\Actions\Approvals\Queries\ApprovalReview;
use App\Actions\Approvals\ReassignApprovalStep;
use App\Data\Approvals\ReviewedApproval;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Models\Identity\Membership;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detalhe do pedido para quem aprova: itens e totais retratados,
 * condições, níveis com quórum, aprovadores e decisões (inclusive por
 * delegação) e histórico, com o aviso de que a aprovação interna não
 * garante estoque nem crédito no distribuidor (RN-056).
 *
 * Aprovar (comentário opcional) e rejeitar (motivo obrigatório) passam por
 * DecideOrderApproval; reatribuir os aprovadores do nível bloqueado, por
 * ReassignApprovalStep (portal.approval_rules). As Actions conferem
 * permissões e estado de novo, sob lock; violações viram erro de campo.
 */
#[Layout('layouts.app')]
#[Title('Aprovação de pedido')]
final class ApprovalShow extends Component
{
    public const int MAX_REASON_LENGTH = 500;

    public const int MAX_APPROVERS = 50;

    #[Locked]
    public string $orderId = '';

    /** Nível pendente exibido na última renderização (ReviewedApproval). */
    #[Locked]
    public string $reviewedStepId = '';

    /** Impressão do pedido exibida na última renderização (ReviewedApproval). */
    #[Locked]
    public string $reviewedFingerprint = '';

    public string $comment = '';

    /** @var array<array-key, mixed> associações escolhidas para o nível (entrada não confiável) */
    public array $approvers = [];

    public string $reassignReason = '';

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public string $messageType = 'success';

    #[Locked]
    public ?string $failure = null;

    public function mount(string $order, ApprovalReview $review): void
    {
        $actor = $review->actor();
        $found = $review->find($actor, $order);
        $this->orderId = $found->id;

        if ($review->managesRules()) {
            $this->approvers = $review->review($actor, $found)->suggestedApprovers;
        }
    }

    public function approve(ApprovalReview $review, DecideOrderApproval $decide): void
    {
        $this->decide($review, $decide, ApprovalDecision::Approved);
    }

    public function reject(ApprovalReview $review, DecideOrderApproval $decide): void
    {
        $this->decide($review, $decide, ApprovalDecision::Rejected);
    }

    public function reassign(ApprovalReview $review, ReassignApprovalStep $reassign): void
    {
        $this->clearFeedback();
        $actor = $review->ruleManager();
        $order = $review->find($actor, $this->orderId);
        $data = $review->review($actor, $order);

        if (! $data->canReassign || $data->current === null) {
            $this->failure = 'Só é possível reatribuir os aprovadores de um nível pendente bloqueado (sem aprovadores elegíveis suficientes para o quórum).';

            return;
        }

        if (count($this->approvers) > self::MAX_APPROVERS) {
            $this->addError('approvers', 'Escolha até '.self::MAX_APPROVERS.' aprovadores.');

            return;
        }

        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): string => UntrustedId::uuid($id, Membership::class),
            array_values($this->approvers),
        )));

        if ($ids === []) {
            $this->addError('approvers', 'Escolha ao menos um aprovador para o nível.');

            return;
        }

        if (mb_strlen(trim($this->reassignReason)) > self::MAX_REASON_LENGTH) {
            $this->addError('reassignReason', 'O motivo deve ter até '.self::MAX_REASON_LENGTH.' caracteres.');

            return;
        }

        try {
            $level = $reassign->handle($order, $ids, $this->reassignReason, $this->reviewedStepId);
        } catch (BusinessRuleViolation $e) {
            $this->violation($e, ['reason' => 'reassignReason', 'approvers' => 'approvers']);

            return;
        }

        $this->reset('reassignReason');
        $this->approvers = $review->review($actor, $order)->suggestedApprovers;
        $this->message = "Aprovadores do nível {$level} reatribuídos. Os aprovadores escolhidos já podem decidir.";
    }

    public function render(ApprovalReview $review, Clock $clock): View
    {
        $actor = $review->actor();
        $order = $review->find($actor, $this->orderId);

        $data = $review->review($actor, $order);
        // O que esta renderização mostra é o que uma decisão ou reatribuição
        // enviada a partir dela pode alterar.
        $reviewed = $data->reviewed();
        $this->reviewedStepId = $reviewed->stepId;
        $this->reviewedFingerprint = $reviewed->fingerprint;

        return view('livewire.portal.approvals.inbox.approval-show', [
            'review' => $data,
            'membershipId' => $actor->membership->id,
            'now' => $clock->now(),
        ])->title("Aprovação do pedido {$order->number}");
    }

    private function decide(ApprovalReview $review, DecideOrderApproval $decide, ApprovalDecision $decision): void
    {
        $this->clearFeedback();
        $order = $review->find($review->actor(), $this->orderId);

        try {
            $outcome = $decide->handle($order, $decision, $this->comment, new ReviewedApproval($this->reviewedStepId, $this->reviewedFingerprint));
        } catch (BusinessRuleViolation $e) {
            $this->violation($e, ['comment' => 'comment']);

            return;
        }

        $this->reset('comment');
        $this->message = $outcome->message();
        $this->messageType = match ($outcome) {
            ApprovalOutcome::Pending, ApprovalOutcome::Confirmed => 'success',
            ApprovalOutcome::Rejected => 'info',
            ApprovalOutcome::AwaitingConfirmation, ApprovalOutcome::Invalidated => 'warning',
        };
    }

    /**
     * Violação no campo indicado pela regra (details['field']) ou como
     * aviso geral da tela.
     *
     * @param  array<string, string>  $fields  campo da regra => propriedade do componente
     */
    private function violation(BusinessRuleViolation $e, array $fields): void
    {
        $field = $e->details()['field'] ?? null;

        if (is_string($field) && isset($fields[$field])) {
            $this->addError($fields[$field], $e->getMessage());

            return;
        }

        $this->failure = $e->getMessage();
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->messageType = 'success';
        $this->failure = null;
    }
}
