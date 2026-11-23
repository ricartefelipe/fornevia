<?php

declare(strict_types=1);

use App\Actions\Ordering\CancelOrderItems;
use App\Domain\Approvals\Exceptions\ApprovalOutdated;
use App\Domain\Platform\Exceptions\SubscriptionInactive;
use App\Domain\Shared\Quantity;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\OrderItem;
use App\Services\Pricing\PriceVersion;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Reavaliação antes da confirmação (RN-054, RF-061) e encerramento da
| aprovação na rejeição e no cancelamento, com o orçamento (RN-055).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->manager = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->director = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->cfo = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->center = Fx::costCenter($this->scenario, $this->company);
    $this->adjustStock = fn (string $delta): mixed => $this->scenario->system(fn () => app(App\Services\Inventory\StockLedger::class)
        ->adjust($this->scenario->warehouse->id, $this->scenario->sku->id, Quantity::of($delta), 'Quebra no depósito'));
    $this->reprice = function (int $cents): void {
        $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->update(['unit_price_cents' => $cents]));
        $this->scenario->system(static fn () => app(PriceVersion::class)->bump());
    };
});

it('troca os níveis pelos da regra que passa a valer quando o novo total cruza a alçada', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]], priority: 1, name: 'Gerência');
    Fx::rule($this->scenario, $this->company, [[1, [$this->director]], [1, [$this->cfo]]], ['min_total_cents' => 35_000], priority: 5, name: 'Diretoria');
    $budget = Fx::budget($this->scenario, $this->center, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    expect(Fx::fresh($this->scenario, $order)->approval_rule_name)->toBe('Gerência');

    ($this->reprice)(12_000);

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::Invalidated);

    $stored = Fx::fresh($this->scenario, $order);
    $all = $this->scenario->system(fn () => OrderApprovalStep::query()->where('order_id', $order->id)->get());

    expect($stored->total_cents->cents)->toBe(36_000)
        ->and($stored->approval_rule_name)->toBe('Diretoria')
        ->and($stored->approvalSteps->map(static fn (OrderApprovalStep $s): array => [$s->level_number, $s->approver_membership_ids])->all())
        ->toBe([[1, [$this->director->id]], [2, [$this->cfo->id]]])
        ->and($all->countBy(static fn (OrderApprovalStep $s): string => $s->status->value)->sortKeys()->all())->toBe(['pending' => 2, 'superseded' => 1])
        ->and($all->firstWhere('status', ApprovalStepStatus::Superseded)?->approver_membership_ids)->toBe([$this->manager->id])
        // Reserva refeita pelo novo total.
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 36_000, 'consumed' => 0])
        ->and(fn () => Fx::decide($this->scenario, $this->manager, $order))->toThrow(App\Domain\Approvals\Exceptions\ApprovalNotAllowed::class);

    expect(Fx::decide($this->scenario, $this->director, $order))->toBe(ApprovalOutcome::Pending)
        ->and(Fx::decide($this->scenario, $this->cfo, $order))->toBe(ApprovalOutcome::Confirmed)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 36_000]);
});

it('mantém os níveis retratados quando nenhuma regra vale para o novo total', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]], ['min_total_cents' => 25_000]);
    $order = Fx::place($this->scenario, $this->buyer);

    ($this->reprice)(8_000);

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::Invalidated);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->total_cents->cents)->toBe(24_000)
        ->and($stored->approvalSteps->pluck('status')->all())->toBe([ApprovalStepStatus::Pending])
        ->and(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::Confirmed);
});

it('não confirma item inativado durante a aprovação', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    $this->scenario->system(fn () => DB::table('skus')->where('id', $this->scenario->sku->id)->update(['status' => 'inactive']));

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::AwaitingConfirmation);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::Approved)
        ->and($stored->confirmation_issue)->toBe("O item {$this->scenario->sku->code} não está mais disponível para a empresa.")
        ->and(Fx::reservations($this->scenario, $order))->toBe(0);
});

it('não confirma item que deixou de atender à quantidade mínima', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    $this->scenario->system(fn () => DB::table('skus')->where('id', $this->scenario->sku->id)->update(['min_order_quantity' => '5']));

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::AwaitingConfirmation)
        ->and(Fx::fresh($this->scenario, $order)->confirmation_issue)->toContain('regras de compra');
});

it('invalida as aprovações quando a condição de pagamento passa a exigir crédito', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    $this->scenario->system(fn () => DB::table('payment_terms')->where('id', $this->scenario->paymentTerm->id)
        ->update(['requires_credit' => true, 'installment_days' => json_encode([30])]));

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::Invalidated);

    $stored = Fx::fresh($this->scenario, $order);
    $event = $this->scenario->system(fn () => DB::table('order_events')->where('order_id', $order->id)->where('type', 'order.approvals_invalidated')->value('data'));

    expect($stored->payment_term['requires_credit'])->toBeTrue()
        ->and($stored->payment_term['installment_days'])->toBe([30])
        ->and($stored->approval_status)->toBe(ApprovalStatus::Pending)
        ->and(json_decode((string) $event, true)['reasons'])->toBe(['payment_term']);
});

it('recusa a submissão para aprovação com a avaliação do distribuidor vencida', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]]);
    $this->scenario->system(fn () => DB::table('subscriptions')->where('organization_id', $this->scenario->tenant->organization->id)
        ->update(['status' => 'trialing', 'trial_ends_at' => now()->subDay()]));

    expect(fn () => Fx::place($this->scenario, $this->buyer))->toThrow(SubscriptionInactive::class)
        ->and($this->scenario->system(static fn (): int => DB::table('orders')->count()))->toBe(0);
});

it('encerra os níveis seguintes quando um nível rejeita', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]], [1, [$this->director]], [1, [$this->cfo]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Fx::decide($this->scenario, $this->manager, $order);

    expect(Fx::decide($this->scenario, $this->director, $order, App\Enums\Approvals\ApprovalDecision::Rejected, 'Fora do planejamento da obra'))
        ->toBe(ApprovalOutcome::Rejected);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->approval_status)->toBe(ApprovalStatus::Rejected)
        ->and($stored->approvalSteps->pluck('status')->all())->toBe([ApprovalStepStatus::Approved, ApprovalStepStatus::Rejected, ApprovalStepStatus::Cancelled]);
});

it('encerra a aprovação pendente quando o pedido é cancelado', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]], [1, [$this->director]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Fx::decide($this->scenario, $this->manager, $order);

    $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(CancelOrderItems::class)->handle($order, null, 'Compra feita por engano'));

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::Cancelled)
        ->and($stored->approval_status)->toBe(ApprovalStatus::Cancelled)
        ->and($stored->approvalSteps->pluck('status')->all())->toBe([ApprovalStepStatus::Approved, ApprovalStepStatus::Cancelled]);
});

it('estorna o consumo do orçamento ao cancelar pedido confirmado, parcial e totalmente', function (): void {
    $budget = Fx::budget($this->scenario, $this->center, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    expect(Fx::fresh($this->scenario, $order)->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 30_000]);

    $item = $this->scenario->system(fn (): OrderItem => OrderItem::query()->where('order_id', $order->id)->firstOrFail());
    $cancel = fn (?array $quantities) => $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(CancelOrderItems::class)->handle($order, $quantities, 'Obra adiada pelo cliente'));

    $cancel([$item->id => Quantity::of('1')]);
    expect(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 20_000]);

    $cancel(null);
    expect(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 0])
        ->and($this->scenario->system(fn (): array => DB::table('budget_movements')->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->pluck('type')->all()))
        ->toBe(['consumption', 'reversal', 'reversal']);
});

it('recusa decisão tomada sobre a impressão anterior à reavaliação', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager, $this->director]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $seen = Fx::reviewed($this->scenario, $order);

    ($this->reprice)(12_000);
    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::Invalidated);

    // Mesmo nível, nova impressão: a tela aberta antes da reavaliação não decide.
    expect(Fx::reviewed($this->scenario, $order)->stepId)->toBe($seen->stepId)
        ->and(fn () => Fx::decide($this->scenario, $this->director, $order, reviewed: $seen))->toThrow(ApprovalOutdated::class, 'O pedido mudou')
        ->and(Fx::decide($this->scenario, $this->director, $order))->toBe(ApprovalOutcome::Confirmed);
});

it('recusa decisão e reatribuição sobre nível que já não está em análise', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager, $this->director]], [1, [$this->cfo]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $levelOne = Fx::reviewed($this->scenario, $order);
    $admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::Pending)
        ->and(fn () => Fx::decide($this->scenario, $this->director, $order, reviewed: $levelOne))->toThrow(ApprovalOutdated::class)
        ->and(fn () => $this->scenario->tenant->actingAs($admin, fn () => app(App\Actions\Approvals\ReassignApprovalStep::class)
            ->handle($order, [$this->director->id], 'Diretor substitui o financeiro', $levelOne->stepId)))
        ->toThrow(ApprovalOutdated::class, 'Outro nível entrou em análise')
        ->and($this->scenario->system(fn (): int => App\Models\Approvals\OrderApprovalDecision::query()->where('order_id', $order->id)->count()))->toBe(1);
});

it('registra na decisão a impressão do pedido decidido', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]], [1, [$this->director]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $fingerprint = Fx::fresh($this->scenario, $order)->approval_fingerprint;

    Fx::decide($this->scenario, $this->manager, $order);

    expect($this->scenario->system(fn () => App\Models\Approvals\OrderApprovalDecision::query()->where('order_id', $order->id)->value('order_fingerprint')))
        ->toBe($fingerprint)->not->toBeNull();
});

it('audita a nova tentativa de confirmação com o resultado', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    ($this->adjustStock)('-49');

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::AwaitingConfirmation)
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(App\Actions\Approvals\RetryOrderConfirmation::class)->handle($order)))
        ->toBe(ApprovalOutcome::AwaitingConfirmation);

    $audit = $this->scenario->system(fn () => DB::table('audit_logs')->where('action', 'order.confirmation_retried')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->subject_id)->toBe($order->id)
        ->and($audit->actor_membership_id)->toBe($this->buyer['membership']->id)
        ->and(json_decode((string) $audit->data, true))->toBe(['outcome' => 'awaiting_confirmation']);
});

it('limpa o motivo de confirmação pendente ao rejeitar e ao cancelar', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]], [1, [$this->director]]]);
    Fx::budget($this->scenario, $this->center, 32_000);
    $rejected = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);
    Fx::decide($this->scenario, $this->manager, $rejected);
    ($this->reprice)(12_000);
    Fx::decide($this->scenario, $this->director, $rejected);

    expect(Fx::fresh($this->scenario, $rejected)->confirmation_issue)->toContain('orçamento');

    Fx::decide($this->scenario, $this->manager, $rejected, App\Enums\Approvals\ApprovalDecision::Rejected, 'Fora do orçamento do mês');
    expect(Fx::fresh($this->scenario, $rejected)->confirmation_issue)->toBeNull();

    $other = $this->scenario->buyerWithCart(quantity: '1', sameCompany: true);
    $approved = Fx::place($this->scenario, $other);
    ($this->adjustStock)('-50');
    Fx::decide($this->scenario, $this->manager, $approved);
    expect(Fx::decide($this->scenario, $this->director, $approved))->toBe(ApprovalOutcome::AwaitingConfirmation);

    $this->scenario->tenant->actingAs($other['membership'], fn () => app(CancelOrderItems::class)->handle($approved, null, 'Compra feita por engano'));

    expect(Fx::fresh($this->scenario, $approved)->confirmation_issue)->toBeNull();
});
