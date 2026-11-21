<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;

/**
 * Regras de decisão em um nível de aprovação (RN-050 a RN-053):
 *
 * - o solicitante não aprova o próprio pedido, nem como delegado, e não
 *   conta como aprovador (RN-051, RN-053);
 * - só decide quem é aprovador elegível do nível (associação ativa com
 *   permissão de aprovar) ou delegado vigente de um aprovador elegível;
 * - cada pessoa e cada titular decidem uma única vez por nível;
 * - um nível sem aprovadores elegíveis suficientes para atingir o quórum
 *   fica bloqueado para a administração (RN-052).
 */
final class ApprovalFlow
{
    /**
     * Titular em nome de quem o ator decide: ele mesmo, se for aprovador
     * elegível do nível; senão, o primeiro titular elegível que lhe delegou
     * e ainda não decidiu.
     *
     * @param  list<string>  $eligibleApproverIds  aprovadores do nível ainda elegíveis
     * @param  list<string>  $delegatorIds  titulares com delegação vigente para o ator
     */
    public static function principalFor(
        ApprovalStepView $step,
        string $actorId,
        string $requesterId,
        array $eligibleApproverIds,
        array $delegatorIds,
    ): string {
        if ($actorId === $requesterId) {
            throw new ApprovalNotAllowed('Quem solicitou a compra não pode aprová-la.');
        }

        if ($step->hasDecided($actorId)) {
            throw new ApprovalNotAllowed('Você já registrou uma decisão neste nível.');
        }

        $eligible = array_values(array_filter($eligibleApproverIds, static fn (string $id): bool => $id !== $requesterId));

        if (in_array($actorId, $eligible, true)) {
            return $actorId;
        }

        foreach ($delegatorIds as $delegatorId) {
            if (in_array($delegatorId, $eligible, true) && ! $step->hasDecided($delegatorId)) {
                return $delegatorId;
            }
        }

        throw new ApprovalNotAllowed('Você não é aprovador deste nível nem tem delegação vigente de um aprovador.');
    }

    /**
     * O quórum ainda pode ser atingido com os aprovadores elegíveis que não
     * decidiram somados às aprovações já válidas?
     *
     * @param  list<string>  $eligibleApproverIds
     */
    public static function isBlocked(ApprovalStepView $step, string $requesterId, array $eligibleApproverIds): bool
    {
        $approved = count($step->approvedPrincipals($requesterId));
        $remaining = count(array_filter(
            $eligibleApproverIds,
            static fn (string $id): bool => $id !== $requesterId && ! $step->hasDecided($id),
        ));

        return ! $step->isRejected() && $approved + $remaining < $step->requiredApprovals;
    }
}
