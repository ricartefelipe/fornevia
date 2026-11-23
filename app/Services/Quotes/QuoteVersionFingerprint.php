<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use Carbon\CarbonImmutable;

/**
 * Impressão do conteúdo de uma versão (RN-040): linhas (SKU, quantidade,
 * preço de referência, desconto e total), totais, condição de pagamento,
 * validade, prazo da reserva e observações. Gravada na inserção da versão;
 * o aceite e a aprovação comercial valem só para a impressão exibida.
 */
final class QuoteVersionFingerprint
{
    /**
     * @param  list<array{sku_id: string, quantity: string, unit_price_cents: int, discount_bps: int, line_total_cents: int}>  $lines
     * @param  array<string, mixed>  $paymentTerm
     */
    public static function of(array $lines, int $subtotalCents, int $totalCents, array $paymentTerm, CarbonImmutable $validUntil, ?CarbonImmutable $stockHoldUntil, ?string $notes): string
    {
        return hash('sha256', json_encode([
            'lines' => array_map(static fn (array $line): array => [
                $line['sku_id'], $line['quantity'], $line['unit_price_cents'], $line['discount_bps'], $line['line_total_cents'],
            ], $lines),
            'subtotal' => $subtotalCents,
            'total' => $totalCents,
            'payment_term' => [
                $paymentTerm['code'] ?? null,
                $paymentTerm['installment_days'] ?? null,
                (bool) ($paymentTerm['requires_credit'] ?? false),
            ],
            'valid_until' => $validUntil->utc()->format('Y-m-d\TH:i:s\Z'),
            'stock_hold_until' => $stockHoldUntil?->utc()->format('Y-m-d\TH:i:s\Z'),
            'notes' => $notes,
        ], JSON_THROW_ON_ERROR));
    }
}
