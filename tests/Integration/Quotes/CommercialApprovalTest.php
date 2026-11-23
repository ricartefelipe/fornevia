<?php

declare(strict_types=1);

use App\Actions\Quotes\Staff\DecideCommercialApproval;
use App\Actions\Quotes\Staff\SendQuoteVersion;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Quotes\Exceptions\CommercialApprovalNotAllowed;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Models\Quotes\QuoteVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;

/*
| Aprovação comercial vinculada à versão (RN-041), distinta da aprovação
| interna do comprador.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->quote = F::request($this->scenario, $this->buyer);
    $this->rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->owner = $this->scenario->tenant->ownerMembership;
    $this->scenario->system(fn () => Illuminate\Support\Facades\DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => $this->rep->id]));
    $this->decide = fn ($who, ReviewedQuoteVersion $seen, bool $approve, ?string $comment = null, bool $send = false): QuoteVersion => $this->scenario->tenant->actingAs($who, fn (): QuoteVersion => app(DecideCommercialApproval::class)
        ->handle($this->quote->id, $seen, $approve, $comment, $send));
});

it('aprova e envia a versão do representante por quem tem limite', function (): void {
    $version = F::submit($this->scenario, $this->rep, $this->quote, discount: '12', overrides: ['justification' => 'Concorrente cobriu o preço']);
    $seen = ReviewedQuoteVersion::of($version);

    ($this->decide)($this->manager, $seen, approve: true, send: true);
    $quote = F::fresh($this->scenario, $this->quote);

    expect($quote->status)->toBe(QuoteStatus::Proposed)
        ->and($quote->versions->first()->status)->toBe(QuoteVersionStatus::Sent)
        ->and($quote->versions->first()->review->decision)->toBe('approved')
        ->and($quote->versions->first()->review->decider_limit_bps)->toBe(1_500)
        ->and($quote->versions->first()->review->self_approved)->toBeFalse();
});

it('exige limite suficiente, outra pessoa que não o autor e permissão de aprovar', function (): void {
    $version = F::submit($this->scenario, $this->rep, $this->quote, discount: '20', overrides: ['justification' => 'Concorrente cobriu o preço']);
    $seen = ReviewedQuoteVersion::of($version);

    expect(fn () => ($this->decide)($this->manager, $seen, approve: true))->toThrow(CommercialApprovalNotAllowed::class, 'limite')
        ->and(fn () => ($this->decide)($this->rep, $seen, approve: true))->toThrow(AuthorizationException::class);

    $ownVersion = F::submit($this->scenario, $this->manager, $this->quote, discount: '20', overrides: ['justification' => 'Volume fechado para o trimestre']);

    expect(fn () => ($this->decide)($this->manager, ReviewedQuoteVersion::of($ownVersion), approve: true))->toThrow(CommercialApprovalNotAllowed::class, 'Quem elaborou')
        ->and(($this->decide)($this->owner, ReviewedQuoteVersion::of($ownVersion), approve: true)->status)->toBe(QuoteVersionStatus::Ready);
});

it('reprova com motivo e não reaproveita a decisão em outra versão', function (): void {
    $version = F::submit($this->scenario, $this->rep, $this->quote, discount: '12', overrides: ['justification' => 'Concorrente cobriu o preço']);
    $seen = ReviewedQuoteVersion::of($version);

    expect(fn () => ($this->decide)($this->manager, $seen, approve: false, comment: 'não'))->toThrow(InvalidQuote::class, 'motivo');

    ($this->decide)($this->manager, $seen, approve: false, comment: 'Margem abaixo do aceitável');

    expect(F::fresh($this->scenario, $this->quote)->versions->first()->status)->toBe(QuoteVersionStatus::ApprovalRejected)
        ->and(fn () => ($this->decide)($this->manager, $seen, approve: true))->toThrow(QuoteVersionOutdated::class);
});

it('não decide versão retirada por nova submissão nem com impressão diferente', function (): void {
    $version = F::submit($this->scenario, $this->rep, $this->quote, discount: '12', overrides: ['justification' => 'Concorrente cobriu o preço']);
    $seen = ReviewedQuoteVersion::of($version);
    F::submit($this->scenario, $this->rep, $this->quote, discount: '11', overrides: ['justification' => 'Concorrente cobriu o preço']);

    expect(fn () => ($this->decide)($this->manager, $seen, approve: true))->toThrow(QuoteVersionOutdated::class)
        ->and(fn () => ($this->decide)($this->manager, new ReviewedQuoteVersion($seen->versionId, str_repeat('0', 64)), approve: true))->toThrow(QuoteVersionOutdated::class);
});

it('aprova sem enviar e deixa o envio para quem elabora', function (): void {
    $version = F::submit($this->scenario, $this->rep, $this->quote, discount: '12', overrides: ['justification' => 'Concorrente cobriu o preço']);
    ($this->decide)($this->manager, ReviewedQuoteVersion::of($version), approve: true);
    $state = F::state($this->scenario, $this->quote);

    $sent = $this->scenario->tenant->actingAs($this->rep, fn (): QuoteVersion => app(SendQuoteVersion::class)->handle($this->quote->id, $state, $version->id));

    expect($sent->status)->toBe(QuoteVersionStatus::Sent)
        ->and($sent->version_number)->toBe(1);
});
