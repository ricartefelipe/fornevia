<?php

declare(strict_types=1);

use App\Actions\Approvals\Queries\ApprovalReviewData;
use App\Actions\Approvals\Queries\PendingStepState;
use App\Actions\Approvals\Queries\StepReview;
use App\Domain\Approvals\ApprovalStepView;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\Order;

/*
| Versão exibida ao aprovador: vem do nível que o formulário oferece, mesmo
| quando a lista de níveis foi lida um instante antes de o nível anterior
| ser concluído.
*/

function reviewDataStep(string $id, int $level, ApprovalStepStatus $status): OrderApprovalStep
{
    return (new OrderApprovalStep)->forceFill(['id' => $id, 'level_number' => $level, 'required_approvals' => 1, 'approver_membership_ids' => [], 'status' => $status]);
}

it('usa o nível do formulário e a impressão do pedido exibido como versão revisada', function (): void {
    $order = (new Order)->forceFill(['approval_fingerprint' => str_repeat('f', 64)]);
    // Lista lida antes: nível 1 ainda pendente. Formulário lido depois: nível 2.
    $listed = [
        new StepReview(reviewDataStep('nivel-1', 1, ApprovalStepStatus::Pending), 0, false, false, [], []),
        new StepReview(reviewDataStep('nivel-2', 2, ApprovalStepStatus::Pending), 0, false, false, [], []),
        new StepReview(reviewDataStep('antigo-1', 1, ApprovalStepStatus::Superseded), 0, false, false, [], []),
    ];
    $levelTwo = reviewDataStep('nivel-2', 2, ApprovalStepStatus::Pending);
    $current = new PendingStepState($levelTwo, new ApprovalStepView('nivel-2', 2, 1, [], []), 'solicitante', [], 'aprovadora', null, false);

    $data = new ApprovalReviewData($order, null, null, null, $listed, $current, null, false, [], [], []);
    $withoutForm = new ApprovalReviewData($order, null, null, null, $listed, null, null, false, [], [], []);

    expect($data->reviewed()->stepId)->toBe('nivel-2')
        ->and($data->reviewed()->fingerprint)->toBe(str_repeat('f', 64))
        ->and($data->levelCount())->toBe(2)
        ->and($withoutForm->reviewed()->stepId)->toBe('');
});
