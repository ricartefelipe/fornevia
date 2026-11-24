<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Enums\Finance\PaymentGatewayKind;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recebe um webhook do Asaas para um distribuidor (RF-112): confere o token
 * pelo hash, em tempo constante (vale mesmo com outro gateway ativo, porque
 * cobranças antigas continuam no Asaas), e grava o evento uma vez.
 */
final readonly class ReceiveAsaasWebhook
{
    public const string NOT_FOUND = 'not_found';

    public const string UNAUTHORIZED = 'unauthorized';

    public const string INVALID = 'invalid';

    public const string RECORDED = 'recorded';

    public const string DUPLICATE = 'duplicate';

    public function __construct(private TenantDatabase $db, private GatewayEvents $events) {}

    /**
     * @param  array<mixed>  $payload
     */
    public function handle(string $organizationId, string $token, array $payload): string
    {
        if (! Str::isUuid($organizationId)) {
            return self::NOT_FOUND;
        }

        $context = ExecutionContext::system($organizationId);
        $expected = $this->db->run($context, static fn (): mixed => DB::table('payment_gateway_settings')->value('webhook_token_hash'));

        if (! is_string($expected) || $token === '' || ! hash_equals($expected, hash('sha256', $token))) {
            return self::UNAUTHORIZED;
        }

        $eventId = $payload['id'] ?? null;
        $type = $payload['event'] ?? null;
        $payment = $payload['payment'] ?? null;

        if (! is_string($eventId) || ! is_string($type) || ! is_array($payment)) {
            return self::INVALID;
        }

        $chargeId = is_string($payment['id'] ?? null) ? $payment['id'] : null;
        $new = $this->db->run($context, fn (): bool => $this->events->record($organizationId, PaymentGatewayKind::Asaas, $eventId, $type, $chargeId, ['event' => $type, 'payment' => $payment]));

        return $new ? self::RECORDED : self::DUPLICATE;
    }
}
