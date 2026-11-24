<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Domain\Shared\Money;
use App\Enums\Finance\PaymentGatewayKind;

/**
 * Gateway de pagamento (RF-101). Toda criação de cobrança procura antes
 * pela referência externa: um timeout depois de a cobrança ter sido criada
 * não gera cobrança repetida. Falha de comunicação sem como conferir lança
 * GatewayUnavailable.
 */
interface PaymentGateway
{
    public function kind(): PaymentGatewayKind;

    /**
     * @throws GatewayUnavailable
     */
    public function charge(ChargeRequest $request): ChargeResult;

    /**
     * @throws GatewayUnavailable
     */
    public function find(string $reference): ?ChargeResult;

    /**
     * @throws GatewayUnavailable
     */
    public function refund(string $chargeId, Money $amount): string;
}
