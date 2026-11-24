<?php

declare(strict_types=1);

namespace App\Actions\Finance\Staff;

use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Enums\Finance\PaymentGatewayKind;
use App\Enums\Identity\Permission;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gateway de pagamento do distribuidor (RF-101): simulador ou Asaas, com a
 * chave de API cifrada e um token de webhook gerado aqui, mostrado uma
 * única vez (só o hash fica gravado). Trocar para o Asaas exige chave.
 */
final readonly class SavePaymentGatewaySettings
{
    public function __construct(private Access $access, private AuditRecorder $audit, private Clock $clock) {}

    /**
     * @return string|null token do webhook gerado agora (mostrar uma vez), ou null
     */
    public function handle(PaymentGatewayKind $gateway, ?string $apiKey, bool $newWebhookToken): ?string
    {
        $this->access->authorize(Permission::FinanceManage);
        $tenantId = $this->access->membership()->tenant_id;
        $apiKey = $apiKey !== null && trim($apiKey) !== '' ? trim($apiKey) : null;

        if ($gateway === PaymentGatewayKind::Simulator && ! PaymentGateways::simulatorEnabled()) {
            throw new InvalidPayment('O simulador não está disponível neste ambiente.', ['field' => 'gateway']);
        }

        if ($apiKey !== null && (mb_strlen($apiKey) < 20 || mb_strlen($apiKey) > 300)) {
            throw new InvalidPayment('Chave de API inválida.', ['field' => 'api_key']);
        }

        return DB::transaction(function () use ($tenantId, $gateway, $apiKey, $newWebhookToken): ?string {
            $current = DB::table('payment_gateway_settings')->lockForUpdate()->first(['api_key_encrypted', 'webhook_token_hash']);

            if ($gateway === PaymentGatewayKind::Asaas && $apiKey === null && ! is_string($current->api_key_encrypted ?? null)) {
                throw new InvalidPayment('Informe a chave de API do Asaas.', ['field' => 'api_key']);
            }

            $token = $newWebhookToken || ($gateway === PaymentGatewayKind::Asaas && ! is_string($current->webhook_token_hash ?? null)) ? Str::random(48) : null;
            $now = $this->clock->now();
            $values = array_filter([
                'gateway' => $gateway->value,
                'api_key_encrypted' => $apiKey !== null ? Crypt::encryptString($apiKey) : null,
                'webhook_token_hash' => $token !== null ? hash('sha256', $token) : null,
                'updated_at' => $now,
            ], static fn (mixed $v): bool => $v !== null);

            $current === null
                ? DB::table('payment_gateway_settings')->insert(['tenant_id' => $tenantId, 'created_at' => $now, ...$values])
                : DB::table('payment_gateway_settings')->update($values);

            $this->audit->record('payment_gateway.updated', null, ['gateway' => $gateway->value, 'api_key_changed' => $apiKey !== null, 'webhook_token_changed' => $token !== null]);

            return $token;
        });
    }
}
