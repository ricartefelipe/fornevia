<?php

declare(strict_types=1);

namespace App\Actions\Finance\Staff;

use App\Actions\Finance\CreateCharge;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Identity\Permission;
use App\Models\Finance\Payment;
use App\Support\Auth\Access;

/**
 * A equipe do financeiro gera a cobrança de uma parcela no gateway, para
 * enviar o link ao comprador.
 */
final readonly class ChargeInstallment
{
    public function __construct(private Access $access, private CreateCharge $charges) {}

    public function handle(mixed $installmentId, PaymentMethod $method): Payment
    {
        $this->access->authorize(Permission::FinanceManage);

        return $this->charges->handle($installmentId, $method);
    }
}
