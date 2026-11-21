<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Support;

use App\Domain\Shared\Money;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use Carbon\CarbonImmutable;

/**
 * Leitura da aprovação interna de um pedido para as telas do portal e da
 * equipe: níveis, aprovadores, quórum atingido, decisões e rótulos dos
 * eventos. Lida no contexto de quem consulta.
 *
 * @phpstan-type ApprovalLevel array{
 *     id: string,
 *     number: int,
 *     required: int,
 *     status: ApprovalStepStatus,
 *     current: bool,
 *     decidedAt: CarbonImmutable|null,
 *     approvers: list<string>,
 *     approvals: int,
 *     decisions: list<array{id: string, actor: string, principal: string|null, decision: ApprovalDecision, comment: string|null, at: CarbonImmutable, invalidatedAt: CarbonImmutable|null}>
 * }
 */
final class OrderApprovalSummary
{
    /**
     * Rótulos dos eventos da aprovação interna.
     *
     * @var array<string, string>
     */
    public const array EVENT_LABELS = [
        'order.submitted_for_approval' => 'Pedido enviado para aprovação interna',
        'order.approval_recorded' => 'Aprovação registrada',
        'order.approval_level_approved' => 'Nível de aprovação concluído',
        'order.approved' => 'Pedido aprovado internamente',
        'order.approval_rejected' => 'Pedido rejeitado na aprovação interna',
        'order.approvals_invalidated' => 'Aprovações invalidadas pela reavaliação de preços',
        'order.approval_step_reassigned' => 'Aprovadores do nível reatribuídos',
        'order.confirmation_pending' => 'Confirmação com o distribuidor pendente',
    ];

    /**
     * Aprovado internamente e ainda não confirmado com o distribuidor.
     */
    public static function awaitsConfirmation(Order $order): bool
    {
        return $order->commercial_status === CommercialStatus::Approved && $order->approval_status === ApprovalStatus::Approved;
    }

    /**
     * Níveis de aprovação retratados na submissão, com os aprovadores, o
     * quórum atingido (titulares distintos, sem o solicitante) e as
     * decisões, inclusive as invalidadas pela reavaliação. Lido no contexto
     * de quem consulta: comprador da empresa ou equipe do distribuidor.
     *
     * @return list<ApprovalLevel>
     */
    public static function levels(Order $order): array
    {
        $steps = OrderApprovalStep::query()->where('order_id', $order->id)->orderBy('level_number')->get();

        if ($steps->isEmpty()) {
            return [];
        }

        $decisions = OrderApprovalDecision::query()
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('order_approval_step_id');

        $membershipIds = [];

        foreach ($steps as $step) {
            array_push($membershipIds, ...array_values($step->approver_membership_ids));
        }

        foreach ($decisions->flatten() as $decision) {
            /** @var OrderApprovalDecision $decision */
            $membershipIds[] = $decision->actor_membership_id;
            $membershipIds[] = $decision->principal_membership_id;
        }

        $names = Membership::query()
            ->with('user')
            ->whereKey(array_values(array_unique($membershipIds)))
            ->get()
            ->mapWithKeys(static fn (Membership $m): array => [$m->id => $m->user->name])
            ->all();
        $name = static fn (string $id): string => $names[$id] ?? 'Usuário não identificado';
        $requester = (string) $order->placed_by_membership_id;
        $currentId = $order->approval_status === ApprovalStatus::Pending
            ? $steps->first(static fn (OrderApprovalStep $s): bool => $s->status === ApprovalStepStatus::Pending)?->id
            : null;
        $levels = [];

        foreach ($steps as $step) {
            /** @var \Illuminate\Support\Collection<int, OrderApprovalDecision> $stepDecisions */
            $stepDecisions = $decisions->get($step->id, collect());
            $approvedBy = [];

            foreach ($stepDecisions as $decision) {
                if ($decision->invalidated_at === null && $decision->decision === ApprovalDecision::Approved
                    && $decision->principal_membership_id !== $requester && $decision->actor_membership_id !== $requester) {
                    $approvedBy[$decision->principal_membership_id] = true;
                }
            }

            $levels[] = [
                'id' => $step->id,
                'number' => $step->level_number,
                'required' => $step->required_approvals,
                'status' => $step->status,
                'current' => $step->id === $currentId,
                'decidedAt' => $step->decided_at,
                'approvers' => array_values(array_map($name, $step->approver_membership_ids)),
                'approvals' => count($approvedBy),
                'decisions' => array_values($stepDecisions->map(static fn (OrderApprovalDecision $d): array => [
                    'id' => $d->id,
                    'actor' => $name($d->actor_membership_id),
                    'principal' => $d->principal_membership_id !== $d->actor_membership_id ? $name($d->principal_membership_id) : null,
                    'decision' => $d->decision,
                    'comment' => $d->comment,
                    'at' => $d->created_at,
                    'invalidatedAt' => $d->invalidated_at,
                ])->all()),
            ];
        }

        return $levels;
    }

    /**
     * Detalhes legíveis dos eventos da aprovação interna (regra, nível e
     * totais).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function eventDetails(array $data, bool $withTotal = false): array
    {
        $details = [];

        if ($withTotal && isset($data['total_cents']) && is_int($data['total_cents'])) {
            $details[] = 'Total: '.Money::ofCents($data['total_cents'])->format();
        }

        if (isset($data['rule']) && is_string($data['rule'])) {
            $details[] = 'Regra: '.$data['rule'];
        }

        if (isset($data['level']) && is_int($data['level'])) {
            $details[] = 'Nível '.$data['level'];
        }

        if (isset($data['previous_total_cents']) && is_int($data['previous_total_cents'])) {
            $details[] = 'Total anterior: '.Money::ofCents($data['previous_total_cents'])->format();
        }

        return $details;
    }
}
