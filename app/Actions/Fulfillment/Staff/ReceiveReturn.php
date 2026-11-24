<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidReturn;
use App\Domain\Fulfillment\Exceptions\ReturnNotAllowed;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\ReturnItem;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\ReturnLocks;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Outbox\Outbox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recebimento e inspeção da devolução autorizada (RF-091).
 *
 * - receive(): registra o que chegou por item (até o autorizado); o que
 *   chegou conta como devolvido no pedido e a parte aberta se encerra. Sem
 *   inspeção exigida, o recebido volta ao disponível e a devolução fecha.
 * - inspect(): aprova ou reprova o recebido; só o aprovado volta ao
 *   disponível no depósito da devolução (return_receipt). O valor a
 *   devolver (preço líquido do pedido × aprovado) fica registrado para o
 *   financeiro.
 *
 * Ordem de locks (ADR-006): pedido → devolução → itens → saldos.
 */
final readonly class ReceiveReturn
{
    public function __construct(
        private Access $access,
        private ReturnLocks $locks,
        private StockLedger $stock,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, string>  $received  return_item_id => quantidade recebida (pt-BR); ausente = tudo
     */
    public function receive(mixed $returnId, array $received): ReturnRequest
    {
        $this->access->authorize(Permission::ReturnsManage);
        $request = ReturnRequest::query()->findOrFail(UntrustedId::uuid($returnId, ReturnRequest::class));

        return DB::transaction(function () use ($request, $received): ReturnRequest {
            [, $locked] = $this->locks->lock($request);

            if ($locked->status !== ReturnStatus::Authorized) {
                throw new ReturnNotAllowed('Só a devolução autorizada é recebida.');
            }

            [$lines, $items] = $this->locks->items($locked);
            $total = Quantity::zero();

            foreach ($lines as $line) {
                $quantity = self::quantity($received[$line->id] ?? null, $line->quantity, "received.{$line->id}");

                if ($quantity->greaterThan($line->quantity)) {
                    throw new InvalidReturn('A quantidade recebida vai de zero até a autorizada.', ['field' => "received.{$line->id}"]);
                }

                $line->forceFill(['quantity_received' => $quantity])->save();
                // Devolvido e em aberto mudam juntos: o CHECK do item vale a cada linha gravada.
                $item = $items->get($line->order_item_id);
                $item?->forceFill([
                    'quantity_returned' => $item->quantity_returned->plus($quantity),
                    'quantity_return_open' => $item->quantity_return_open->minus($line->quantity),
                ])->save();
                $total = $total->plus($quantity);
            }

            $locked->forceFill(['status' => ReturnStatus::Received, 'received_at' => $this->clock->now(), 'received_by_user_id' => $this->access->userId()])->save();

            if (! $total->isPositive()) {
                // Nada chegou: a devolução fecha sem valor nem estoque.
                $locked->forceFill(['status' => ReturnStatus::Closed, 'closed_at' => $this->clock->now()])->save();
                $this->outbox->record('return.closed', 'return_request', $locked->id, ['return_id' => $locked->id, 'order_id' => $locked->order_id, 'number' => $locked->number, 'refund_cents' => 0]);
            } elseif (! $locked->inspection_required) {
                $this->approve($locked, $lines, $items, array_fill_keys($lines->pluck('id')->all(), null), null);
            }

            $this->audit->record('return.received', $locked, ['buyer_company_id' => $locked->buyer_company_id]);

            return $locked;
        });
    }

    /**
     * @param  array<string, string>  $approved  return_item_id => quantidade aprovada (pt-BR); ausente = todo o recebido
     * @param  array<string, string>  $notes  return_item_id => observação da inspeção
     */
    public function inspect(mixed $returnId, array $approved, array $notes = []): ReturnRequest
    {
        $this->access->authorize(Permission::ReturnsManage);
        $request = ReturnRequest::query()->findOrFail(UntrustedId::uuid($returnId, ReturnRequest::class));

        return DB::transaction(function () use ($request, $approved, $notes): ReturnRequest {
            [, $locked] = $this->locks->lock($request);

            if ($locked->status !== ReturnStatus::Received) {
                throw new ReturnNotAllowed('Só a devolução recebida aguarda inspeção.');
            }

            [$lines, $items] = $this->locks->items($locked);
            $this->approve($locked, $lines, $items, $approved, $notes);
            $this->audit->record('return.inspected', $locked, ['refund_cents' => $locked->refund_cents->cents, 'buyer_company_id' => $locked->buyer_company_id]);

            return $locked;
        });
    }

    /**
     * @param  Collection<int, ReturnItem>  $lines
     * @param  Collection<string, OrderItem>  $items
     * @param  array<string, string|null>  $approved
     * @param  array<string, string>|null  $notes
     */
    private function approve(ReturnRequest $request, Collection $lines, Collection $items, array $approved, ?array $notes): void
    {
        $refund = Money::zero();
        $entries = [];

        foreach ($lines as $line) {
            $quantity = self::quantity($approved[$line->id] ?? null, $line->quantity_received, "approved.{$line->id}");

            if ($quantity->greaterThan($line->quantity_received)) {
                throw new InvalidReturn('A quantidade aprovada vai de zero até a recebida.', ['field' => "approved.{$line->id}"]);
            }

            $note = trim((string) ($notes[$line->id] ?? ''));
            $line->forceFill([
                'quantity_approved' => $quantity,
                'quantity_rejected' => $line->quantity_received->minus($quantity),
                'inspection_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            ])->save();
            $item = $items->get($line->order_item_id);

            if ($item !== null && $quantity->isPositive()) {
                $entries[] = [$item, $quantity];
                $refund = $refund->plus($item->unit_net_price_cents->times($quantity));
            }
        }

        // Entradas na ordem (depósito, SKU) do ADR-006.
        usort($entries, static fn (array $a, array $b): int => strcmp($a[0]->sku_id, $b[0]->sku_id));

        foreach ($entries as [$item, $quantity]) {
            $this->stock->receiveReturn((string) $request->warehouse_id, $item->sku_id, $quantity->convertedBy($item->conversion_factor), $request->id, "Devolução {$request->number}");
        }

        $request->forceFill(['status' => ReturnStatus::Closed, 'closed_at' => $this->clock->now(), 'refund_cents' => $refund])->save();
        $this->outbox->record('return.closed', 'return_request', $request->id, ['return_id' => $request->id, 'order_id' => $request->order_id, 'number' => $request->number, 'refund_cents' => $refund->cents]);
    }

    private static function quantity(?string $value, Quantity $default, string $field): Quantity
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $default;
        }

        try {
            $quantity = Quantity::parseLocalized($value);
        } catch (InvalidArgumentException) {
            throw new InvalidReturn('Quantidade inválida.', ['field' => $field]);
        }

        if ($quantity->isNegative()) {
            throw new InvalidReturn('Quantidade inválida.', ['field' => $field]);
        }

        return $quantity;
    }
}
