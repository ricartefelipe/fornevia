<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalContext;
use App\Domain\Approvals\ApprovalCriteria;
use App\Domain\Approvals\ApprovalFlow;
use App\Domain\Approvals\ApprovalStepView;
use App\Domain\Approvals\BudgetPosition;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Domain\Shared\Money;

/*
| Regras puras de aprovação de compras (RF-050, RN-050 a RN-055).
*/

function step(int $required, array $approvers, array $decisions = []): ApprovalStepView
{
    return new ApprovalStepView('nivel-1', 1, $required, $approvers, $decisions);
}

it('casa a regra apenas quando todos os critérios informados são atendidos', function (): void {
    $context = new ApprovalContext(Money::ofCents(150_000), 'cc-obras', 'un-centro', ['cat-eletrica', 'cat-hidraulica'], 'purchaser');

    expect((new ApprovalCriteria)->matches($context))->toBeTrue()
        ->and((new ApprovalCriteria(minTotal: Money::ofCents(150_000)))->matches($context))->toBeTrue()
        ->and((new ApprovalCriteria(minTotal: Money::ofCents(150_001)))->matches($context))->toBeFalse()
        ->and((new ApprovalCriteria(costCenterId: 'cc-obras', buyerUnitId: 'un-centro'))->matches($context))->toBeTrue()
        ->and((new ApprovalCriteria(costCenterId: 'cc-adm'))->matches($context))->toBeFalse()
        ->and((new ApprovalCriteria(buyerUnitId: 'un-filial'))->matches($context))->toBeFalse()
        ->and((new ApprovalCriteria(categoryId: 'cat-hidraulica'))->matches($context))->toBeTrue()
        ->and((new ApprovalCriteria(categoryId: 'cat-limpeza'))->matches($context))->toBeFalse()
        ->and((new ApprovalCriteria(requesterRoleKey: 'requester'))->matches($context))->toBeFalse();
});

it('exige quórum de titulares distintos, aprovadores do nível, e ignora o solicitante', function (): void {
    $partial = step(2, ['ana', 'bruno', 'carla'], [['actor' => 'ana', 'principal' => 'ana', 'approved' => true]]);
    $full = step(2, ['ana', 'bruno'], [
        ['actor' => 'ana', 'principal' => 'ana', 'approved' => true],
        ['actor' => 'davi', 'principal' => 'bruno', 'approved' => true],
    ]);
    $withRequester = step(2, ['ana', 'sol'], [
        ['actor' => 'ana', 'principal' => 'ana', 'approved' => true],
        ['actor' => 'sol', 'principal' => 'sol', 'approved' => true],
    ]);
    $withOutsider = step(2, ['ana', 'bruno'], [
        ['actor' => 'ana', 'principal' => 'ana', 'approved' => true],
        ['actor' => 'edu', 'principal' => 'edu', 'approved' => true],
    ]);

    expect($partial->isApproved('sol'))->toBeFalse()
        ->and($full->isApproved('sol'))->toBeTrue()
        ->and($full->approvedPrincipals('sol'))->toBe(['ana', 'bruno'])
        ->and($withRequester->isApproved('sol'))->toBeFalse()
        // Só contam titulares que são aprovadores do nível.
        ->and($withOutsider->approvedPrincipals('sol'))->toBe(['ana'])
        ->and($withOutsider->isApproved('sol'))->toBeFalse();
});

it('encerra o nível na primeira rejeição', function (): void {
    $rejected = step(1, ['ana', 'bruno'], [['actor' => 'ana', 'principal' => 'ana', 'approved' => false]]);

    expect($rejected->isRejected())->toBeTrue()
        ->and($rejected->isApproved('sol'))->toBeFalse()
        ->and(ApprovalFlow::isBlocked($rejected, 'sol', ['bruno']))->toBeFalse();
});

it('define em nome de quem o ator decide, inclusive por delegação', function (): void {
    $step = step(1, ['ana', 'bruno']);

    expect(ApprovalFlow::principalFor($step, 'ana', 'sol', ['ana', 'bruno'], []))->toBe('ana')
        ->and(ApprovalFlow::principalFor($step, 'davi', 'sol', ['ana', 'bruno'], ['bruno']))->toBe('bruno');
});

it('impede autoaprovação, decisão repetida, delegação do solicitante e não aprovadores', function (ApprovalStepView $step, string $actor, array $eligible, array $delegators, string $message): void {
    expect(fn () => ApprovalFlow::principalFor($step, $actor, 'sol', $eligible, $delegators))
        ->toThrow(ApprovalNotAllowed::class, $message);
})->with([
    'solicitante aprovador' => [step(1, ['sol', 'ana']), 'sol', ['sol', 'ana'], [], 'Quem solicitou'],
    'decisão repetida' => [step(2, ['ana', 'bruno'], [['actor' => 'ana', 'principal' => 'ana', 'approved' => true]]), 'ana', ['ana', 'bruno'], [], 'já registrou'],
    'titular já decidiu' => [step(2, ['ana', 'bruno'], [['actor' => 'ana', 'principal' => 'ana', 'approved' => true]]), 'davi', ['ana', 'bruno'], ['ana'], 'não é aprovador'],
    'delegação do solicitante' => [step(1, ['sol', 'ana']), 'davi', ['sol', 'ana'], ['sol'], 'não é aprovador'],
    'aprovador revogado' => [step(1, ['ana', 'bruno']), 'ana', ['bruno'], [], 'não é aprovador'],
    'sem vínculo' => [step(1, ['ana']), 'eva', ['ana'], [], 'não é aprovador'],
]);

it('bloqueia o nível quando o quórum ficou inalcançável', function (): void {
    expect(ApprovalFlow::isBlocked(step(2, ['ana', 'bruno']), 'sol', ['ana', 'bruno']))->toBeFalse()
        ->and(ApprovalFlow::isBlocked(step(2, ['ana', 'bruno']), 'sol', ['ana']))->toBeTrue()
        ->and(ApprovalFlow::isBlocked(step(1, ['sol']), 'sol', ['sol']))->toBeTrue()
        ->and(ApprovalFlow::isBlocked(step(2, ['ana', 'bruno'], [['actor' => 'ana', 'principal' => 'ana', 'approved' => true]]), 'sol', ['bruno']))->toBeFalse();
});

it('calcula o orçamento disponível e se o valor cabe', function (): void {
    $budget = new BudgetPosition(Money::ofCents(100_000), Money::ofCents(30_000), Money::ofCents(50_000));

    expect($budget->available()->cents)->toBe(20_000)
        ->and($budget->fits(Money::ofCents(20_000)))->toBeTrue()
        ->and($budget->fits(Money::ofCents(20_001)))->toBeFalse();
});
