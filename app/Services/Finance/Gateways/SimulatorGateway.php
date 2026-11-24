<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Domain\Shared\Money;
use App\Enums\Finance\PaymentGatewayKind;
use Illuminate\Support\Str;

/**
 * Gateway local para desenvolvimento e demonstração: a cobrança tem id
 * determinístico pela referência e a página do simulador confirma o
 * pagamento gerando o mesmo evento que um webhook real.
 */
final class SimulatorGateway implements PaymentGateway
{
    public function kind(): PaymentGatewayKind
    {
        return PaymentGatewayKind::Simulator;
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        return new ChargeResult('sim_'.$request->reference, ChargeResult::PENDING, route('payments.simulator', ['payment' => $request->reference], false));
    }

    public function find(string $reference): ?ChargeResult
    {
        return null;
    }

    public function cancel(string $chargeId): void {}

    public function refund(string $chargeId, Money $amount): string
    {
        return 'sim_refund_'.Str::uuid7();
    }
}
