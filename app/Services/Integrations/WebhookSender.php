<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Models\Integrations\WebhookDelivery;
use App\Models\Integrations\WebhookEndpoint;
use App\Support\Clock\Clock;
use App\Support\Integrations\WebhookUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Envia uma entrega de webhook (RF-111), fora de transação: corpo JSON com
 * o id estável do evento; cabeçalhos com o tipo, o id da entrega, o
 * carimbo de tempo e a assinatura HMAC-SHA256 de "timestamp.corpo" com o
 * segredo do endpoint; conexão no endereço conferido contra SSRF, sem
 * seguir redirecionamentos. Devolve o resultado para a entrega registrar.
 */
final readonly class WebhookSender
{
    public function __construct(private Clock $clock) {}

    /**
     * @return array{ok: bool, status: int|null, error: string|null}
     */
    public function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): array
    {
        try {
            $target = WebhookUrlGuard::resolve($endpoint->url);
        } catch (InvalidIntegration $e) {
            return ['ok' => false, 'status' => null, 'error' => $e->getMessage()];
        }

        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) $this->clock->now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, Crypt::decryptString($endpoint->secret_encrypted));

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Fornevia-Webhooks/1',
                'X-Fornevia-Event' => $delivery->event_type,
                'X-Fornevia-Delivery' => $delivery->id,
                'X-Fornevia-Timestamp' => $timestamp,
                'X-Fornevia-Signature' => 't='.$timestamp.',v1='.$signature,
            ])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]],
                ])
                ->timeout((int) config('integrations.webhooks.timeout_seconds', 10))
                ->connectTimeout(5)
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => null, 'error' => 'Sem resposta do endpoint: '.mb_substr($e->getMessage(), 0, 300)];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'error' => mb_substr($e::class.': '.$e->getMessage(), 0, 300)];
        }

        return $response->successful()
            ? ['ok' => true, 'status' => $response->status(), 'error' => null]
            : ['ok' => false, 'status' => $response->status(), 'error' => 'Resposta '.$response->status().' do endpoint.'];
    }

    /**
     * Assinatura para o recebedor conferir (documentação e testes).
     */
    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
