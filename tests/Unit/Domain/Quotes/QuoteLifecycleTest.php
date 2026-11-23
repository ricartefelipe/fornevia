<?php

declare(strict_types=1);

use App\Domain\Quotes\QuoteLifecycle;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;

/*
| Transições da cotação e da versão (ADR-025).
*/

it('permite só as transições previstas da cotação e mantém as terminais', function (QuoteStatus $from, array $allowed): void {
    foreach (QuoteStatus::cases() as $to) {
        expect(QuoteLifecycle::canMoveQuote($from, $to))->toBe(in_array($to, $allowed, true), "{$from->value} → {$to->value}");
    }

    expect($from->isTerminal())->toBe($allowed === []);
})->with([
    'solicitada' => [QuoteStatus::Requested, [QuoteStatus::Proposed, QuoteStatus::Cancelled]],
    'proposta' => [QuoteStatus::Proposed, [QuoteStatus::Requested, QuoteStatus::Expired, QuoteStatus::Accepted, QuoteStatus::Rejected, QuoteStatus::Cancelled]],
    'vencida' => [QuoteStatus::Expired, [QuoteStatus::Proposed, QuoteStatus::Requested, QuoteStatus::Cancelled]],
    'aceita' => [QuoteStatus::Accepted, []],
    'recusada' => [QuoteStatus::Rejected, []],
    'cancelada' => [QuoteStatus::Cancelled, []],
]);

it('permite só as transições previstas da versão', function (QuoteVersionStatus $from, array $allowed): void {
    foreach (QuoteVersionStatus::cases() as $to) {
        expect(QuoteLifecycle::canMoveVersion($from, $to))->toBe(in_array($to, $allowed, true), "{$from->value} → {$to->value}");
    }

    expect($from->isOpen())->toBe($allowed !== [])
        ->and($from->isPreparing())->toBe(in_array($from, [QuoteVersionStatus::PendingApproval, QuoteVersionStatus::Ready], true));
})->with([
    'em aprovação' => [QuoteVersionStatus::PendingApproval, [QuoteVersionStatus::Ready, QuoteVersionStatus::ApprovalRejected, QuoteVersionStatus::Withdrawn, QuoteVersionStatus::Expired]],
    'pronta' => [QuoteVersionStatus::Ready, [QuoteVersionStatus::Sent, QuoteVersionStatus::Withdrawn, QuoteVersionStatus::Expired]],
    'enviada' => [QuoteVersionStatus::Sent, [QuoteVersionStatus::Accepted, QuoteVersionStatus::Superseded, QuoteVersionStatus::Countered, QuoteVersionStatus::Rejected, QuoteVersionStatus::Expired, QuoteVersionStatus::Withdrawn]],
    'aceita' => [QuoteVersionStatus::Accepted, []],
    'substituída' => [QuoteVersionStatus::Superseded, []],
    'contraproposta' => [QuoteVersionStatus::Countered, []],
    'recusada' => [QuoteVersionStatus::Rejected, []],
    'vencida' => [QuoteVersionStatus::Expired, []],
    'retirada' => [QuoteVersionStatus::Withdrawn, []],
    'reprovada' => [QuoteVersionStatus::ApprovalRejected, []],
]);

it('recusa a transição fora do mapa e aceita a mesma situação', function (): void {
    expect(fn () => QuoteLifecycle::assertQuoteMove(QuoteStatus::Accepted, QuoteStatus::Proposed))->toThrow(LogicException::class, 'accepted para proposed')
        ->and(fn () => QuoteLifecycle::assertVersionMove(QuoteVersionStatus::Expired, QuoteVersionStatus::Sent))->toThrow(LogicException::class, 'expired para sent');

    QuoteLifecycle::assertQuoteMove(QuoteStatus::Proposed, QuoteStatus::Proposed);
    QuoteLifecycle::assertVersionMove(QuoteVersionStatus::Sent, QuoteVersionStatus::Accepted);
    expect(true)->toBeTrue();
});
