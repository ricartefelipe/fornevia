<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Domain\Shared\Money;
use App\Enums\Finance\PaymentGatewayKind;
use App\Enums\Finance\PaymentMethod;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador do Asaas (API v3, sandbox ou produção). Cliente encontrado ou
 * criado pelo CPF/CNPJ; cobrança criada só depois de procurar pela
 * referência externa, e procurada de novo quando a criação não responde
 * (RF-101). Cartão é pago na página do Asaas: nenhum dado de cartão passa
 * pelo Fornevia (RS-100).
 */
final readonly class AsaasGateway implements PaymentGateway
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeoutSeconds = 15,
    ) {}

    public function kind(): PaymentGatewayKind
    {
        return PaymentGatewayKind::Asaas;
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $existing = $this->find($request->reference);

        if ($existing !== null) {
            return $existing;
        }

        $payload = [
            'customer' => $this->customer($request),
            'billingType' => match ($request->method) {
                PaymentMethod::Pix => 'PIX',
                PaymentMethod::Card => 'CREDIT_CARD',
                default => 'BOLETO',
            },
            'value' => $request->amount->cents / 100,
            'dueDate' => $request->dueDate->toDateString(),
            'description' => mb_substr($request->description, 0, 500),
            'externalReference' => $request->reference,
        ];

        try {
            $response = $this->client()->post('/payments', $payload)->throw();
        } catch (ConnectionException) {
            // A cobrança pode ter sido criada antes do tempo esgotar.
            return $this->find($request->reference) ?? throw new GatewayUnavailable('O Asaas não respondeu ao criar a cobrança. Tente de novo em instantes.');
        } catch (RequestException $e) {
            throw new GatewayUnavailable('O Asaas recusou a cobrança: '.self::error($e->response).'.');
        }

        return self::result((array) $response->json());
    }

    public function find(string $reference): ?ChargeResult
    {
        try {
            $data = (array) $this->client()->get('/payments', ['externalReference' => $reference, 'limit' => 1])->throw()->json('data', []);
        } catch (ConnectionException|RequestException) {
            throw new GatewayUnavailable('Não foi possível consultar a cobrança no Asaas.');
        }

        $first = $data[0] ?? null;

        return is_array($first) ? self::result($first) : null;
    }

    public function refund(string $chargeId, Money $amount): string
    {
        try {
            $response = $this->client()->post('/payments/'.rawurlencode($chargeId).'/refund', ['value' => $amount->cents / 100])->throw();
        } catch (ConnectionException) {
            throw new GatewayUnavailable('O Asaas não respondeu ao reembolso. Confira a cobrança antes de tentar de novo.');
        } catch (RequestException $e) {
            throw new GatewayUnavailable('O Asaas recusou o reembolso: '.self::error($e->response).'.');
        }

        return (string) ($response->json('id') ?? $chargeId);
    }

    /**
     * @param  array<mixed>  $payment
     */
    public static function result(array $payment): ChargeResult
    {
        $status = (string) ($payment['status'] ?? 'PENDING');
        $value = isset($payment['value']) ? Money::ofCents((int) round(((float) $payment['value']) * 100)) : null;
        $net = isset($payment['netValue']) ? Money::ofCents((int) round(((float) $payment['netValue']) * 100)) : null;

        return new ChargeResult(
            (string) ($payment['id'] ?? ''),
            match ($status) {
                'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => ChargeResult::CONFIRMED,
                'REFUNDED', 'OVERDUE', 'DELETED', 'CHARGEBACK_REQUESTED', 'REFUND_REQUESTED' => ChargeResult::FAILED,
                default => ChargeResult::PENDING,
            },
            isset($payment['invoiceUrl']) ? (string) $payment['invoiceUrl'] : null,
            $value,
            $value !== null && $net !== null ? Money::max(Money::zero(), $value->minus($net)) : null,
        );
    }

    private function customer(ChargeRequest $request): string
    {
        $document = (string) preg_replace('/\D/', '', $request->payerDocument);

        try {
            $found = (array) $this->client()->get('/customers', ['cpfCnpj' => $document, 'limit' => 1])->throw()->json('data', []);

            if (isset($found[0]['id'])) {
                return (string) $found[0]['id'];
            }

            return (string) $this->client()->post('/customers', array_filter([
                'name' => $request->payerName, 'cpfCnpj' => $document, 'email' => $request->payerEmail,
            ]))->throw()->json('id');
        } catch (ConnectionException) {
            throw new GatewayUnavailable('O Asaas não respondeu ao registrar o pagador.');
        } catch (RequestException $e) {
            throw new GatewayUnavailable('O Asaas recusou o pagador: '.self::error($e->response).'.');
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['access_token' => $this->apiKey, 'User-Agent' => 'Fornevia'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(5, $this->timeoutSeconds));
    }

    private static function error(Response $response): string
    {
        $message = $response->json('errors.0.description');

        return is_string($message) ? mb_substr($message, 0, 200) : 'código '.$response->status();
    }
}
