<?php

declare(strict_types=1);

namespace App\Actions\Finance\Portal;

use App\Actions\Finance\CreateCharge;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Identity\Permission;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Support\Http\UntrustedId;

/**
 * O comprador paga uma parcela de fatura que ele vê: a cobrança é criada
 * no gateway do distribuidor e o pagamento acontece na página dele.
 */
final readonly class PayInstallment
{
    public function __construct(
        private PortalBuyer $buyer,
        private BuyerInvoices $invoices,
        private CreateCharge $charges,
    ) {}

    public function handle(mixed $installmentId, PaymentMethod $method): Payment
    {
        $actor = $this->buyer->require(Permission::PortalOrdersPlace);
        $installment = ReceivableInstallment::query()->findOrFail(UntrustedId::uuid($installmentId, ReceivableInstallment::class));
        $this->invoices->find($actor, $installment->invoice_id);

        return $this->charges->handle($installment->id, $method);
    }
}
