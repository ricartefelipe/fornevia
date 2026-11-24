<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Portal;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Domain\Fulfillment\Exceptions\ReturnNotAllowed;
use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\ReturnRequest;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\ReturnLocks;
use App\Support\Clock\Clock;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/**
 * O comprador desiste da devolução ainda em análise.
 */
final readonly class CancelReturn
{
    public function __construct(
        private PortalBuyer $buyer,
        private BuyerReturns $returns,
        private ReturnLocks $locks,
        private TenantDatabase $db,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $returnId): ReturnRequest
    {
        $actor = $this->buyer->require(Permission::PortalOrdersPlace);
        $request = $this->returns->find($actor, $returnId);

        return DB::transaction(function () use ($request): ReturnRequest {
            $locked = $this->db->asSystem(function () use ($request): ReturnRequest {
                [, $locked] = $this->locks->lock($request);

                if ($locked->status !== ReturnStatus::Requested) {
                    throw new ReturnNotAllowed('A devolução já foi analisada pelo distribuidor.');
                }

                [$lines, $items] = $this->locks->items($locked);
                $this->locks->releaseOpen($lines, $items);
                $locked->forceFill(['status' => ReturnStatus::Cancelled, 'closed_at' => $this->clock->now()])->save();

                return $locked;
            });

            $this->audit->record('return.cancelled', $locked, ['buyer_company_id' => $locked->buyer_company_id]);

            return $locked;
        });
    }
}
