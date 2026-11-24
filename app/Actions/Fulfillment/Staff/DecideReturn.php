<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidReturn;
use App\Domain\Fulfillment\Exceptions\ReturnNotAllowed;
use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Inventory\Warehouse;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\ReturnLocks;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Análise da devolução pela equipe (RF-091): autoriza, indicando o depósito
 * que recebe e se a inspeção é exigida, ou recusa com justificativa (a
 * quantidade volta a poder ser devolvida).
 */
final readonly class DecideReturn
{
    public function __construct(
        private Access $access,
        private ReturnLocks $locks,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function authorize(mixed $returnId, mixed $warehouseId, bool $inspectionRequired, ?string $comment): ReturnRequest
    {
        $this->access->authorize(Permission::ReturnsManage);
        $warehouse = Warehouse::query()->where('is_active', true)->findOrFail(UntrustedId::uuid($warehouseId, Warehouse::class));

        return $this->decide($returnId, true, self::comment($comment, required: false), $warehouse->id, $inspectionRequired);
    }

    public function reject(mixed $returnId, string $comment): ReturnRequest
    {
        $this->access->authorize(Permission::ReturnsManage);

        return $this->decide($returnId, false, self::comment($comment, required: true), null, true);
    }

    private function decide(mixed $returnId, bool $approve, ?string $comment, ?string $warehouseId, bool $inspectionRequired): ReturnRequest
    {
        $request = ReturnRequest::query()->findOrFail(UntrustedId::uuid($returnId, ReturnRequest::class));

        return DB::transaction(function () use ($request, $approve, $comment, $warehouseId, $inspectionRequired): ReturnRequest {
            [$order, $locked] = $this->locks->lock($request);

            if ($locked->status !== ReturnStatus::Requested) {
                throw new ReturnNotAllowed('A devolução já foi analisada ou cancelada.');
            }

            if (! $approve) {
                [$lines, $items] = $this->locks->items($locked);
                $this->locks->releaseOpen($lines, $items);
            }

            $locked->forceFill([
                'status' => $approve ? ReturnStatus::Authorized : ReturnStatus::Rejected,
                'decided_by_user_id' => $this->access->userId(),
                'decided_at' => $this->clock->now(),
                'decision_comment' => $comment,
                'warehouse_id' => $warehouseId,
                'inspection_required' => $inspectionRequired,
                'closed_at' => $approve ? null : $this->clock->now(),
            ])->save();

            $this->outbox->record('return.decided', 'return_request', $locked->id, ['return_id' => $locked->id, 'order_id' => $order->id, 'number' => $locked->number, 'authorized' => $approve]);
            $this->audit->record($approve ? 'return.authorized' : 'return.rejected', $locked, ['buyer_company_id' => $locked->buyer_company_id], $comment);

            return $locked;
        });
    }

    private static function comment(?string $comment, bool $required): ?string
    {
        $comment = $comment !== null ? trim($comment) : '';

        if ($required && mb_strlen($comment) < 5) {
            throw new InvalidReturn('Explique ao comprador o motivo da recusa (ao menos 5 caracteres).', ['field' => 'comment']);
        }

        if (mb_strlen($comment) > 1000) {
            throw new InvalidReturn('O comentário deve ter até 1.000 caracteres.', ['field' => 'comment']);
        }

        return $comment === '' ? null : $comment;
    }
}
