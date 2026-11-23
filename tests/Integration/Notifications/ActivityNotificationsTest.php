<?php

declare(strict_types=1);

use App\Actions\Notifications\MyNotifications;
use App\Actions\Quotes\Staff\DecideCommercialApproval;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Notifications\UserNotification;
use App\Services\Notifications\ActivityMail;
use App\Support\Outbox\OutboxProcessor;
use App\Support\Outbox\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;

/*
| Notificações das atividades (ADR-026): destinatários pelo estado atual,
| nunca quem causou o evento, no sistema e por e-mail, uma vez por
| mensagem.
*/

beforeEach(function (): void {
    Notification::fake();
    config(['queue.default' => 'sync']);
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->scenario->system(fn () => DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => $this->rep->id]));
    $this->drain = fn (): array => app(OutboxRelay::class)->runOnce('relay-teste', 500);
    $this->inbox = fn (Membership $membership): array => $this->scenario->system(static fn (): array => UserNotification::query()
        ->where('membership_id', $membership->id)->orderBy('created_at')->orderBy('id')->get(['kind', 'url'])->map(static fn (UserNotification $n): array => [$n->kind, $n->url])->all());
    $this->emails = function (): array {
        $sent = [];
        Notification::assertSentOnDemand(ActivityMail::class, function (ActivityMail $mail, array $channels, object $notifiable) use (&$sent): bool {
            $sent[] = $notifiable->routes['mail'];

            return true;
        });

        return $sent;
    };
    $this->email = fn (Membership $membership): string => User::query()->findOrFail($membership->user_id)->email;
});

it('avisa a carteira sobre a solicitação e o comprador sobre a proposta, no sistema e por e-mail', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    ($this->drain)();

    expect(($this->inbox)($this->rep))->toBe([['quote.requested', "/cotacoes/{$quote->id}"]])
        ->and(($this->inbox)($this->manager))->toBe([])
        ->and(($this->inbox)($this->buyer['membership']))->toBe([]);

    F::submit($this->scenario, $this->manager, $quote);
    ($this->drain)();

    expect(($this->inbox)($this->buyer['membership']))->toBe([['quote.proposal_sent', "/portal/cotacoes/{$quote->id}"]])
        ->and(($this->emails)())->toContain(($this->email)($this->rep), ($this->email)($this->buyer['membership']));
});

it('avisa quem pode aprovar a exceção comercial e o autor sobre a decisão', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->rep, $quote, discount: '12', overrides: ['justification' => 'Concorrente cobriu o preço']);
    ($this->drain)();

    expect(($this->inbox)($this->manager))->toContain(['quote.commercial_approval_requested', "/cotacoes/{$quote->id}"])
        ->and(collect(($this->inbox)($this->rep))->pluck(0)->all())->not->toContain('quote.commercial_approval_requested');

    $version = F::fresh($this->scenario, $quote)->versions->first();
    $this->scenario->tenant->actingAs($this->manager, static fn () => app(DecideCommercialApproval::class)->handle($quote->id, ReviewedQuoteVersion::of($version), true, null, false));
    ($this->drain)();

    expect(collect(($this->inbox)($this->rep))->pluck(0)->all())->toContain('quote.commercial_approval_decided');
});

it('avisa os aprovadores do nível pendente, o próximo nível e quem fez o pedido', function (): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    $bia = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    $substitute = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::delegation($this->scenario, $bia, $substitute);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]], [1, [$bia]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    ($this->drain)();

    expect(($this->inbox)($ana))->toBe([['order.submitted_for_approval', "/portal/aprovacoes/{$order->id}"]])
        ->and(($this->inbox)($bia))->toBe([]);

    expect(Fx::decide($this->scenario, $ana, $order))->toBe(ApprovalOutcome::Pending);
    ($this->drain)();

    expect(($this->inbox)($bia))->toBe([['order.approval_level_approved', "/portal/aprovacoes/{$order->id}"]])
        ->and(($this->inbox)($substitute))->toBe([['order.approval_level_approved', "/portal/aprovacoes/{$order->id}"]])
        ->and(count(($this->inbox)($ana)))->toBe(1);

    Fx::decide($this->scenario, $bia, $order, ApprovalDecision::Rejected, 'Fora do planejamento');
    ($this->drain)();

    expect(($this->inbox)($this->buyer['membership']))->toBe([['order.approval_rejected', "/portal/pedidos/{$order->id}"]]);
});

it('avisa quem fez o pedido quando a confirmação fica pendente e quando confirma', function (): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote);
    $fromQuote = F::accept($this->scenario, $this->buyer, $quote);
    // Pedido de cotação com a condição alterada: aprovado, aguarda confirmação.
    $this->scenario->system(fn () => DB::table('payment_terms')->where('id', $this->scenario->paymentTerm->id)->update(['installment_days' => json_encode([15])]));

    expect(Fx::decide($this->scenario, $ana, $fromQuote))->toBe(ApprovalOutcome::AwaitingConfirmation);
    ($this->drain)();

    expect(collect(($this->inbox)($this->buyer['membership']))->pluck(0)->all())->toContain('order.confirmation_pending')
        ->and(collect(($this->inbox)($this->buyer['membership']))->pluck(0)->all())->not->toContain('order.confirmed');

    $this->scenario->system(fn () => DB::table('payment_terms')->where('id', $this->scenario->paymentTerm->id)->update(['installment_days' => json_encode([30])]));
    $order = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true));
    expect(Fx::decide($this->scenario, $ana, $order))->toBe(ApprovalOutcome::Confirmed);
    ($this->drain)();

    $placer = $this->scenario->system(fn (): Membership => Membership::query()->findOrFail($order->placed_by_membership_id));
    expect(($this->inbox)($placer))->toBe([['order.confirmed', "/portal/pedidos/{$order->id}"]]);
});

it('não envia e-mail a quem desligou nem a e-mail não confirmado e não repete na nova entrega', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    ($this->drain)();
    // O representante desliga o e-mail; o comprador não confirmou o e-mail.
    $this->scenario->tenant->actingAs($this->rep, static fn () => app(MyNotifications::class)->setEmailEnabled(false));
    $this->scenario->system(fn () => DB::table('users')->where('id', $this->buyer['membership']->user_id)->update(['email_verified_at' => null]));
    Notification::fake();

    $other = F::request($this->scenario, $this->scenario->buyerWithCart(quantity: '2', sameCompany: true));
    F::submit($this->scenario, $this->manager, $quote);
    ($this->drain)();

    expect(count(($this->inbox)($this->rep)))->toBe(2)
        ->and(($this->inbox)($this->buyer['membership']))->toBe([['quote.proposal_sent', "/portal/cotacoes/{$quote->id}"]]);
    Notification::assertNothingSent();

    // Nova entrega das mesmas mensagens: nada é repetido.
    $ids = DB::connection(OutboxRelay::CONNECTION)->table('outbox_messages')->pluck('id')->all();
    foreach ($ids as $id) {
        app(OutboxProcessor::class)->process((string) $id, $this->scenario->tenant->organization->id);
    }

    expect(count(($this->inbox)($this->rep)))->toBe(2)->and(count(($this->inbox)($this->buyer['membership'])))->toBe(1);
    expect($other->id)->toBeString();
});

it('não avisa quem perdeu a associação e, sem representante, avisa quem elabora propostas', function (): void {
    $this->scenario->system(fn () => DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => null]));
    $this->scenario->system(fn () => Membership::query()->whereKey($this->manager->id)->update(['status' => MembershipStatus::Revoked->value, 'revoked_at' => now()]));
    $quote = F::request($this->scenario, $this->buyer);
    ($this->drain)();

    expect(($this->inbox)($this->rep))->toBe([['quote.requested', "/cotacoes/{$quote->id}"]])
        ->and(($this->inbox)($this->manager))->toBe([])
        ->and(($this->inbox)($this->scenario->tenant->ownerMembership))->toBe([['quote.requested', "/cotacoes/{$quote->id}"]]);
});

it('avisa quem fez o pedido quando a equipe cancela o pedido ainda em aprovação', function (): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $this->scenario->tenant->actingAs($this->manager, static fn () => app(App\Actions\Ordering\CancelOrderItems::class)->handle($order, null, 'Cliente desistiu por telefone'));
    ($this->drain)();

    expect(($this->inbox)($this->buyer['membership']))->toBe([['order.cancelled', "/portal/pedidos/{$order->id}"]]);
});
