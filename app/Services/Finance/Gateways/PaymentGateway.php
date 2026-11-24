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
     * Reembolso: devolve o id do reembolso. GatewayUnavailable quando o
     * gateway recusou (nada foi feito); GatewayOutcomeUnknown quando não
     * respondeu (pode ter feito).
     *
     * @throws GatewayUnavailable
     * @throws \App\Domain\Finance\Exceptions\GatewayOutcomeUnknown
     */
    public function refund(string $chargeId, Money $amount, string $reference): string;

    /**
     * Situação do reembolso identificado pela referência enviada em refund()
     * (conferência dos reembolsos sem resposta).
     *
     * @throws GatewayUnavailable quando o gateway não respondeu agora
     */
    public function refundOutcome(string $chargeId, string $reference): GatewayRefund;

    /**
     * Cancela a cobrança ainda não paga (substituída ou sem saldo a cobrar).
     *
     * @throws GatewayUnavailable
     */
    public function cancel(string $chargeId): void;
}
