<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Domain\Finance\Exceptions\GatewayOutcomeUnknown;
use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\Money;
use App\Enums\Finance\PaymentGatewayKind;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\RefundOutcome;
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

    public function refund(string $chargeId, Money $amount, string $reference): string
    {
        try {
            // A descrição leva a referência do reembolso, para a conferência.
            $response = $this->client()->post('/payments/'.rawurlencode($chargeId).'/refund', [
                'value' => $amount->cents / 100, 'description' => self::refundDescription($reference),
            ])->throw();
        } catch (ConnectionException) {
            // O reembolso pode ter sido feito: nada é repetido até a conferência.
            throw new GatewayOutcomeUnknown('O Asaas não respondeu ao reembolso. Confira no painel do Asaas antes de concluir.');
        } catch (RequestException $e) {
            throw new GatewayUnavailable('O Asaas recusou o reembolso: '.self::error($e->response).'.');
        }

        return (string) ($response->json('id') ?? $chargeId);
    }

    /** Páginas consultadas no máximo por cobrança (100 estornos cada). */
    public const int REFUND_PAGES = 20;

    /**
     * Estornos da cobrança no Asaas (GET /payments/{id}/refunds, paginado),
     * localizados pela referência na descrição. Sem estorno com a
     * referência em nenhuma página: Missing.
     */
    public function refundOutcome(string $chargeId, string $reference): GatewayRefund
    {
        for ($page = 0; $page < self::REFUND_PAGES; $page++) {
            try {
                $response = $this->client()->get('/payments/'.rawurlencode($chargeId).'/refunds', ['offset' => $page * 100, 'limit' => 100])->throw();
            } catch (ConnectionException) {
                throw new GatewayUnavailable('O Asaas não respondeu à consulta dos estornos.');
            } catch (RequestException $e) {
                throw new GatewayUnavailable('O Asaas recusou a consulta dos estornos: '.self::error($e->response).'.');
            }

            foreach ((array) $response->json('data', []) as $refund) {
                if (is_array($refund) && str_contains((string) ($refund['description'] ?? ''), self::refundDescription($reference))) {
                    $outcome = match (strtoupper((string) ($refund['status'] ?? ''))) {
                        'DONE' => RefundOutcome::Done,
                        'CANCELLED', 'REFUSED', 'FAILED' => RefundOutcome::Failed,
                        default => RefundOutcome::Pending,
                    };

                    return new GatewayRefund($outcome, isset($refund['id']) ? (string) $refund['id'] : null);
                }
            }

            if ($response->json('hasMore') !== true) {
                return new GatewayRefund(RefundOutcome::Missing);
            }
        }

        // Páginas demais: sem conclusão nesta rodada.
        return new GatewayRefund(RefundOutcome::Pending);
    }

    private static function refundDescription(string $reference): string
    {
        return 'Fornevia reembolso '.$reference;
    }

    public function cancel(string $chargeId): void
    {
        try {
            $this->client()->delete('/payments/'.rawurlencode($chargeId))->throw();
        } catch (ConnectionException) {
            throw new GatewayUnavailable('O Asaas não respondeu ao cancelar a cobrança.');
        } catch (RequestException $e) {
            // Já paga ou já removida: o webhook ou a conciliação trazem a situação real.
            if ($e->response->status() !== 404) {
                throw new GatewayUnavailable('O Asaas recusou o cancelamento: '.self::error($e->response).'.');
            }
        }
    }

    /**
     * Situação da cobrança no Asaas. Vencida continua pendente (ainda pode
     * ser paga); só removida encerra. O valor pago é "value" (com juros e
     * multa quando pago em atraso; o valor original vem em "originalValue"),
     * e a taxa é value − netValue.
     *
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
                'DELETED' => ChargeResult::FAILED,
                default => ChargeResult::PENDING,
            },
            isset($payment['invoiceUrl']) ? (string) $payment['invoiceUrl'] : null,
            $value,
            $value !== null && $net !== null ? Money::max(Money::zero(), $value->minus($net)) : null,
            isset($payment['originalValue']) ? Money::ofCents((int) round(((float) $payment['originalValue']) * 100)) : null,
        );
    }

    private function customer(ChargeRequest $request): string
    {
        $document = Cnpj::normalize($request->payerDocument);

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
