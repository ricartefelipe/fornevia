<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Enums\Finance\PaymentGatewayKind;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Gateways do distribuidor (ADR-028). O ativo é o escolhido para novas
 * cobranças; cada pagamento continua no gateway que o criou (reembolso,
 * cancelamento e conciliação). A chave do Asaas fica cifrada e vale mesmo
 * com o simulador ativo. O simulador só existe fora de produção.
 *
 * Consulta a configuração no contexto atual: chame no contexto de sistema
 * ou de equipe (o comprador não lê a configuração).
 */
final class PaymentGateways
{
    public static function simulatorEnabled(): bool
    {
        return (bool) config('payments.simulator_enabled', false);
    }

    /** Gateway para novas cobranças; null quando nenhum está disponível. */
    public function active(): ?PaymentGateway
    {
        $settings = DB::table('payment_gateway_settings')->first(['gateway', 'api_key_encrypted']);
        $kind = $settings !== null ? PaymentGatewayKind::tryFrom((string) $settings->gateway) : null;

        if ($kind === PaymentGatewayKind::Asaas && $settings !== null && is_string($settings->api_key_encrypted)) {
            return $this->asaas(Crypt::decryptString($settings->api_key_encrypted));
        }

        return self::simulatorEnabled() ? new SimulatorGateway : null;
    }

    /**
     * Gateway que criou um pagamento.
     *
     * @throws GatewayUnavailable
     */
    public function for(PaymentGatewayKind $kind): PaymentGateway
    {
        if ($kind === PaymentGatewayKind::Simulator) {
            return new SimulatorGateway;
        }

        $key = DB::table('payment_gateway_settings')->value('api_key_encrypted');

        if (! is_string($key)) {
            throw new GatewayUnavailable('A chave do Asaas não está configurada.');
        }

        return $this->asaas(Crypt::decryptString($key));
    }

    public function asaas(string $apiKey): AsaasGateway
    {
        return new AsaasGateway((string) config('payments.asaas.base_url'), $apiKey, (int) config('payments.asaas.timeout_seconds', 15));
    }
}
