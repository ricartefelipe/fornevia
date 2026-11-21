<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Staff;

use App\Domain\Pricing\PriceSource;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Credit\CreditMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderEvent;
use App\Models\Ordering\OrderItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Leitura completa de um pedido para a equipe: cabeçalho com snapshots,
 * itens, estados separados, histórico, reservas por depósito e crédito
 * comprometido. Custos e margens só estão presentes para quem tem
 * catalog.view_costs ($costs é null caso contrário).
 */
final readonly class StaffOrderDetail
{
    /** Situações em que CancelOrderItems aceita cancelamento. */
    private const array CANCELLABLE = [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled];

    /**
     * @param  Collection<int, OrderItem>  $items  em ordem de linha
     * @param  Collection<int, OrderEvent>  $events  em ordem cronológica
     * @param  list<array{warehouse: Warehouse, reservations: list<StockReservation>}>  $reservationsByWarehouse
     * @param  Collection<int, CreditMovement>  $creditMovements  em ordem cronológica
     * @param  array<string, string>  $userNames  user_id => nome
     * @param  array<string, string>  $baseUnits  sku_id => código da unidade base
     * @param  array<string, int>  $unitDecimals  código da unidade de venda => casas decimais
     * @param  array<string, string>  $priceLists  price_list_id => código (somente com pricing.view)
     * @param  array<string, StaffItemCost>|null  $costs  order_item_id => custo; null sem catalog.view_costs
     */
    public function __construct(
        public Order $order,
        public Collection $items,
        public ?BuyerUnit $unit,
        public ?CostCenter $costCenter,
        public ?string $salesRepName,
        public Collection $events,
        public array $reservationsByWarehouse,
        public Collection $creditMovements,
        public array $userNames,
        public array $baseUnits,
        public array $unitDecimals,
        public array $priceLists,
        public ?array $costs,
    ) {}

    public function placedByName(): string
    {
        return $this->userNames[$this->order->placed_by_user_id] ?? 'Usuário não identificado';
    }

    /**
     * Saldo ainda não expedido nem cancelado, na unidade de venda.
     */
    public static function openQuantity(OrderItem $item): Quantity
    {
        return $item->quantity->minus($item->quantity_cancelled)->minus($item->quantity_shipped);
    }

    public function isCancellable(): bool
    {
        return in_array($this->order->commercial_status, self::CANCELLABLE, true)
            && $this->items->contains(static fn (OrderItem $item): bool => self::openQuantity($item)->isPositive());
    }

    public function baseUnit(OrderItem $item): string
    {
        return $this->baseUnits[$item->sku_id] ?? $item->unit_code;
    }

    public function unitDecimals(OrderItem $item): int
    {
        return $this->unitDecimals[$item->unit_code] ?? 0;
    }

    /**
     * Origem do preço gravada no snapshot do item.
     */
    public function priceOrigin(OrderItem $item): string
    {
        $snapshot = $item->price_source;
        $source = PriceSource::tryFrom(is_string($snapshot['source'] ?? null) ? $snapshot['source'] : '');

        if ($source === null) {
            return 'Origem não registrada';
        }

        $reference = $snapshot['reference_id'] ?? null;
        $label = $source->label();

        if ($source !== PriceSource::Negotiated && is_string($reference) && isset($this->priceLists[$reference])) {
            $label .= ' '.$this->priceLists[$reference];
        }

        $tier = $snapshot['tier_min_quantity'] ?? null;

        if (is_string($tier) && is_numeric($tier)) {
            $label .= ' · faixa a partir de '.Quantity::of($tier)->format().' '.$item->unit_code;
        }

        return $label;
    }

    public function creditCommitted(): Money
    {
        return Money::sum($this->creditMovements
            ->filter(static fn (CreditMovement $m): bool => $m->type === CreditMovementType::OrderCommitment)
            ->map(static fn (CreditMovement $m): Money => $m->committed_delta));
    }

    public function creditReleased(): Money
    {
        return Money::sum($this->creditMovements
            ->filter(static fn (CreditMovement $m): bool => $m->type === CreditMovementType::CommitmentRelease)
            ->map(static fn (CreditMovement $m): Money => $m->committed_delta->negated()));
    }

    public function eventLabel(OrderEvent $event): string
    {
        return match ($event->type) {
            'order.confirmed' => 'Pedido confirmado',
            'order.items_cancelled' => 'Itens cancelados',
            'order.cancelled' => 'Pedido cancelado',
            default => $event->type,
        };
    }

    /**
     * Detalhes legíveis do evento (itens, valores e motivo).
     *
     * @return list<string>
     */
    public function eventDetails(OrderEvent $event): array
    {
        $data = $event->data;
        $details = [];

        if ($event->type === 'order.confirmed') {
            $total = $data['total_cents'] ?? null;
            $count = $data['items'] ?? null;

            if (is_int($total) && is_int($count)) {
                $details[] = 'Total de '.Money::ofCents($total)->format().' em '.$count.($count === 1 ? ' item.' : ' itens.');
            }

            return $details;
        }

        $items = $this->items->keyBy('id');

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $change) {
            $item = is_array($change) && is_string($change['order_item_id'] ?? null) ? $items->get($change['order_item_id']) : null;
            $quantity = is_array($change) && is_string($change['quantity'] ?? null) && is_numeric($change['quantity']) ? $change['quantity'] : null;

            if ($item !== null && $quantity !== null) {
                $details[] = $item->sku_code.': '.Quantity::of($quantity)->format().' '.$item->unit_code;
            }
        }

        if (is_int($data['value_cents'] ?? null)) {
            $details[] = 'Valor cancelado: '.Money::ofCents($data['value_cents'])->format();
        }

        if (is_string($data['reason'] ?? null) && $data['reason'] !== '') {
            $details[] = 'Motivo: '.$data['reason'];
        }

        return $details;
    }

    public function actorName(OrderEvent|CreditMovement $entry): string
    {
        return $entry->actor_user_id !== null ? ($this->userNames[$entry->actor_user_id] ?? 'Usuário não identificado') : 'Sistema';
    }

    /**
     * Endereço do snapshot em linhas para exibição.
     *
     * @param  array<string, mixed>|null  $address
     * @return list<string>
     */
    public static function addressLines(?array $address): array
    {
        if ($address === null) {
            return [];
        }

        $text = static fn (string $key): string => is_string($address[$key] ?? null) ? trim($address[$key]) : '';
        $join = static fn (string $separator, string ...$parts): string => implode($separator, array_filter($parts, static fn (string $p): bool => $p !== ''));
        $postal = preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $text('postal_code')) ?? '';

        return array_values(array_filter([
            $join(' · ', $text('label'), $text('recipient')),
            $join(', ', $text('street'), $text('number'), $text('complement')),
            $join(' · ', $text('district'), $join('/', $text('city'), $text('state'))),
            $postal !== '' ? 'CEP '.$postal : '',
        ], static fn (string $line): bool => $line !== ''));
    }

    /**
     * Condição de pagamento do snapshot ("28 dias · prazos de 28 dias").
     */
    public function paymentTermLabel(): string
    {
        $term = $this->order->payment_term;
        $name = is_string($term['name'] ?? null) ? $term['name'] : 'Condição não registrada';
        $days = is_array($term['installment_days'] ?? null) ? array_filter($term['installment_days'], 'is_int') : [];
        $credit = ($term['requires_credit'] ?? false) === true ? ' · usa crédito comercial' : '';

        if ($days === [] || $days === [0]) {
            return $name.$credit;
        }

        return $name.' · vencimentos em '.implode('/', $days).' dias'.$credit;
    }
}
