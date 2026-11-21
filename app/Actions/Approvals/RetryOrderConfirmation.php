<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Approvals\Support\ApprovalSteps;
use App\Domain\Approvals\Exceptions\OrderNotAwaitingApproval;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Ordering\Order;
use App\Services\Approvals\ApprovedOrderFinalizer;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Nova tentativa de confirmar um pedido aprovado que não pôde ser
 * confirmado (estoque, crédito, orçamento ou condições), com a mesma
 * reavaliação da aprovação final (RN-054).
 */
final readonly class RetryOrderConfirmation
{
    public function __construct(
        private Access $access,
        private ApprovedOrderFinalizer $finalizer,
        private AuditRecorder $audit,
        private TenantDatabase $db,
    ) {}

    public function handle(Order $order): ApprovalOutcome
    {
        if ($this->access->isStaff()) {
            $this->access->authorize(Permission::OrdersManage);
        } elseif (! ($this->access->isBuyer() && $this->access->can(Permission::PortalOrdersPlace) && $order->buyer_company_id === $this->access->buyerCompanyId()
            && ($order->placed_by_membership_id === $this->access->membership()->id || $this->access->can(Permission::PortalOrdersViewAll)))) {
            throw new AuthorizationException('Sem permissão para confirmar este pedido.');
        }

        return DB::transaction(function () use ($order): ApprovalOutcome {
            // Pedidos são somente leitura no contexto de comprador (ADR-024);
            // a auditoria fica no contexto de quem pediu a nova tentativa.
            $outcome = $this->db->asSystem(fn (): ApprovalOutcome => $this->retry($order));

            $this->audit->record('order.confirmation_retried', $order, ['outcome' => $outcome->value]);

            return $outcome;
        });
    }

    private function retry(Order $order): ApprovalOutcome
    {
        $locked = Order::query()
            ->where('buyer_company_id', $order->buyer_company_id)
            ->lockForUpdate()
            ->findOrFail($order->id);

        if ($locked->commercial_status !== CommercialStatus::Approved || $locked->approval_status !== ApprovalStatus::Approved
            || ApprovalSteps::current($locked) !== null) {
            throw new OrderNotAwaitingApproval('Somente pedidos aprovados e ainda não confirmados podem ser confirmados novamente.');
        }

        return $this->finalizer->finalize($locked);
    }
}
