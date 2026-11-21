<?php

declare(strict_types=1);

namespace Tests\Integration\Approvals;

use App\Actions\Approvals\DecideOrderApproval;
use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Models\Approvals\ApprovalRule;
use App\Models\Approvals\ApprovalRuleLevel;
use App\Models\Approvals\ApprovalRuleLevelApprover;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CostCenter;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/**
 * Apoio aos testes de aprovação: regras, centros de custo e orçamentos
 * gravados no contexto de sistema; checkout e decisões pelas Actions.
 */
final class ApprovalFixtures
{
    /**
     * @param  list<array{0: int, 1: list<Membership>}>  $levels  [quórum, aprovadores]
     * @param  array<string, mixed>  $criteria
     */
    public static function rule(OrderingScenario $scenario, BuyerCompany $company, array $levels, array $criteria = [], int $priority = 0, string $name = 'Compras acima do limite'): ApprovalRule
    {
        return $scenario->system(static function () use ($company, $levels, $criteria, $priority, $name): ApprovalRule {
            $rule = new ApprovalRule;
            $rule->forceFill(['buyer_company_id' => $company->id, 'name' => $name, 'priority' => $priority, 'is_active' => true, ...$criteria])->save();

            foreach (array_values($levels) as $index => [$required, $approvers]) {
                $level = new ApprovalRuleLevel;
                $level->forceFill([
                    'buyer_company_id' => $company->id,
                    'approval_rule_id' => $rule->id,
                    'level_number' => $index + 1,
                    'required_approvals' => $required,
                ])->save();

                foreach ($approvers as $approver) {
                    (new ApprovalRuleLevelApprover)->forceFill([
                        'buyer_company_id' => $company->id,
                        'approval_rule_level_id' => $level->id,
                        'membership_id' => $approver->id,
                    ])->save();
                }
            }

            return $rule;
        });
    }

    public static function costCenter(OrderingScenario $scenario, BuyerCompany $company, string $code = 'OBRAS'): CostCenter
    {
        return $scenario->system(static function () use ($company, $code): CostCenter {
            $center = new CostCenter;
            $center->forceFill(['buyer_company_id' => $company->id, 'code' => $code, 'name' => 'Centro '.$code, 'is_active' => true])->save();

            return $center;
        });
    }

    public static function budget(OrderingScenario $scenario, CostCenter $center, int $amountCents, ?string $month = null): string
    {
        return $scenario->system(static function () use ($center, $amountCents, $month): string {
            $id = (string) Str::uuid7();
            DB::table('cost_center_budgets')->insert([
                'id' => $id,
                'tenant_id' => $center->tenant_id,
                'buyer_company_id' => $center->buyer_company_id,
                'cost_center_id' => $center->id,
                'period_start' => CarbonImmutable::parse($month ?? 'now')->startOfMonth()->toDateString(),
                'amount_cents' => $amountCents,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });
    }

    /**
     * @param  array{membership: Membership, company: BuyerCompany, address: \App\Models\Buyers\BuyerAddress, cart: \App\Models\Ordering\Cart}  $buyer
     * @param  array<string, mixed>  $overrides
     */
    public static function place(OrderingScenario $scenario, array $buyer, array $overrides = []): Order
    {
        $args = $scenario->workerArgs($buyer);
        $data = new PlaceOrderData(...[
            'cartId' => $args['cart_id'],
            'idempotencyKey' => $args['idempotency_key'],
            'shippingAddressId' => $args['shipping_address_id'],
            'paymentTermId' => $args['payment_term_id'],
            ...$overrides,
        ]);

        return $scenario->tenant->actingAs($buyer['membership'], static fn (): Order => app(PlaceOrder::class)->handle($data));
    }

    public static function decide(OrderingScenario $scenario, Membership $approver, Order $order, ApprovalDecision $decision = ApprovalDecision::Approved, ?string $comment = null): ApprovalOutcome
    {
        return $scenario->tenant->actingAs($approver, static fn (): ApprovalOutcome => app(DecideOrderApproval::class)->handle($order, $decision, $comment));
    }

    public static function fresh(OrderingScenario $scenario, Order $order): Order
    {
        return $scenario->system(static fn (): Order => Order::query()->with('approvalSteps')->findOrFail($order->id));
    }

    /**
     * @return array{reserved: int, consumed: int}
     */
    public static function budgetState(OrderingScenario $scenario, string $budgetId): array
    {
        $row = $scenario->system(static fn () => DB::table('cost_center_budgets')->where('id', $budgetId)->first(['reserved_cents', 'consumed_cents']));

        return ['reserved' => (int) $row->reserved_cents, 'consumed' => (int) $row->consumed_cents];
    }

    /**
     * @return list<string>
     */
    public static function events(OrderingScenario $scenario, Order $order): array
    {
        return $scenario->system(static fn (): array => DB::table('order_events')->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->pluck('type')->all());
    }

    public static function reservations(OrderingScenario $scenario, Order $order): int
    {
        return $scenario->system(static fn (): int => DB::table('stock_reservations')
            ->whereIn('order_item_id', DB::table('order_items')->where('order_id', $order->id)->select('id'))
            ->count());
    }
}
