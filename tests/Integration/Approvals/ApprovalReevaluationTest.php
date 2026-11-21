<?php

declare(strict_types=1);

use App\Actions\Ordering\CancelOrderItems;
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
| Reavaliação antes da confirmação (RN-054, RF-061) e efeitos do
| cancelamento sobre a aprovação e o orçamento (RN-055).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->manager = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->director = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->cfo = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->center = Fx::costCenter($this->scenario, $this->company);
    $this->reprice = function (int $cents): void {
        $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->update(['unit_price_cents' => $cents]));
        $this->scenario->system(static fn () => app(PriceVersion::class)->bump());
    };
});

it('troca os níveis pelos da regra que passa a valer quando o novo total cruza a alçada', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->manager]]], priority: 1, name: 'Gerência');
    Fx::rule($this->scenario, $this->company, [[1, [$this->director]], [1, [$this->cfo]]], ['min_total_cents' => 35_000], priority: 5, name: 'Diretoria');
    $order = Fx::place($this->scenario, $this->buyer);

    expect(Fx::fresh($this->scenario, $order)->approval_rule_name)->toBe('Gerência');

    ($this->reprice)(12_000);

    expect(Fx::decide($this->scenario, $this->manager, $order))->toBe(ApprovalOutcome::Invalidated);

    $stored = Fx::fresh($this->scenario, $order);
    $all = $this->scenario->system(fn () => OrderApprovalStep::query()->where('order_id', $order->id)->orderBy('created_at')->get());

    expect($stored->total_cents->cents)->toBe(36_000)
        ->and($stored->approval_rule_name)->toBe('Diretoria')
        ->and($stored->approvalSteps->map(static fn (OrderApprovalStep $s): array => [$s->level_number, $s->approver_membership_ids])->all())
        ->toBe([[1, [$this->director->id]], [2, [$this->cfo->id]]])
        ->and($all->pluck('status')->all())->toBe([ApprovalStepStatus::Superseded, ApprovalStepStatus::Pending, ApprovalStepStatus::Pending])
        ->and(fn () => Fx::decide($this->scenario, $this->manager, $order))->toThrow(App\Domain\Approvals\Exceptions\ApprovalNotAllowed::class);

    expect(Fx::decide($this->scenario, $this->director, $order))->toBe(ApprovalOutcome::Pending)
        ->and(Fx::decide($this->scenario, $this->cfo, $order))->toBe(ApprovalOutcome::Confirmed);
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
