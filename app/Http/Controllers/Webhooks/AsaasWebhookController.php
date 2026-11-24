<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Finance\System\ReceiveAsaasWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook do Asaas por distribuidor (RF-112). Responde 200 também para
 * evento repetido, para o Asaas não reenviar.
 */
final class AsaasWebhookController
{
    public function __invoke(Request $request, string $organization, ReceiveAsaasWebhook $receive): JsonResponse
    {
        return match ($receive->handle($organization, (string) $request->header('asaas-access-token', ''), $request->all())) {
            ReceiveAsaasWebhook::NOT_FOUND => new JsonResponse(['message' => 'Não encontrado.'], 404),
            ReceiveAsaasWebhook::UNAUTHORIZED => new JsonResponse(['message' => 'Não autorizado.'], 401),
            ReceiveAsaasWebhook::INVALID => new JsonResponse(['message' => 'Evento inválido.'], 422),
            ReceiveAsaasWebhook::DUPLICATE => new JsonResponse(['received' => true, 'duplicate' => true]),
            default => new JsonResponse(['received' => true, 'duplicate' => false]),
        };
    }
}
