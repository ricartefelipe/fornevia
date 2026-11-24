<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Enums\Finance\PaymentGatewayKind;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Gateway configurado pelo distribuidor (simulador por padrão). A chave do
 * Asaas fica cifrada; sem chave, o Asaas não é usado.
 */
final class PaymentGateways
{
    public function current(): PaymentGateway
    {
        $settings = DB::table('payment_gateway_settings')->first(['gateway', 'api_key_encrypted']);
        $kind = $settings !== null ? PaymentGatewayKind::tryFrom((string) $settings->gateway) : null;
        $key = $settings !== null ? $settings->api_key_encrypted : null;

        if ($kind === PaymentGatewayKind::Asaas && is_string($key)) {
            return $this->asaas(Crypt::decryptString($key));
        }

        return new SimulatorGateway;
    }

    public function asaas(string $apiKey): AsaasGateway
    {
        return new AsaasGateway((string) config('payments.asaas.base_url'), $apiKey, (int) config('payments.asaas.timeout_seconds', 15));
    }
}
