<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Finance\System\GatewayEvents;
use App\Enums\Finance\PaymentGatewayKind;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webhook do Asaas por distribuidor (RF-112): autenticado pelo token
 * configurado (comparado pelo hash, em tempo constante), mesmo que o
 * gateway ativo tenha mudado (cobranças antigas continuam no Asaas), gravado uma vez
 * por evento e processado pela fila. Responde 200 para evento repetido,
 * para o Asaas não reenviar.
 */
final class AsaasWebhookController
{
    public function __invoke(Request $request, string $organization, TenantDatabase $db, GatewayEvents $events): JsonResponse
    {
        if (! Str::isUuid($organization)) {
            return new JsonResponse(['message' => 'Não encontrado.'], 404);
        }

        $token = (string) $request->header('asaas-access-token', '');
        $expected = $db->run(ExecutionContext::system($organization), static fn (): mixed => DB::table('payment_gateway_settings')->value('webhook_token_hash'));

        if (! is_string($expected) || $token === '' || ! hash_equals($expected, hash('sha256', $token))) {
            return new JsonResponse(['message' => 'Não autorizado.'], 401);
        }

        $eventId = $request->input('id');
        $type = $request->input('event');
        $payment = $request->input('payment');

        if (! is_string($eventId) || ! is_string($type) || ! is_array($payment)) {
            return new JsonResponse(['message' => 'Evento inválido.'], 422);
        }

        $chargeId = is_string($payment['id'] ?? null) ? $payment['id'] : null;
        $new = $db->run(ExecutionContext::system($organization), static fn (): bool => $events->record($organization, PaymentGatewayKind::Asaas, $eventId, $type, $chargeId, ['event' => $type, 'payment' => $payment]));

        return new JsonResponse(['received' => true, 'duplicate' => ! $new]);
    }
}
