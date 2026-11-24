<?php

declare(strict_types=1);

namespace App\Support\Integrations;

/**
 * Eventos oferecidos aos webhooks (RF-111) e os campos públicos de cada um:
 * o corpo enviado nunca leva dados internos da outbox além destes.
 */
final class WebhookEvents
{
    /** @var array<string, array{label: string, fields: list<string>}> */
    public const array EVENTS = [
        'order.confirmed' => ['label' => 'Pedido confirmado', 'fields' => ['order_id', 'number', 'buyer_company_id', 'total_cents']],
        'order.cancelled' => ['label' => 'Pedido cancelado', 'fields' => ['order_id', 'number']],
        'shipment.shipped' => ['label' => 'Remessa expedida', 'fields' => ['shipment_id', 'order_id', 'number', 'carrier', 'tracking_code']],
        'shipment.delivered' => ['label' => 'Remessa entregue', 'fields' => ['shipment_id', 'order_id', 'number']],
        'invoice.issued' => ['label' => 'Fatura emitida', 'fields' => ['invoice_id', 'order_id', 'number', 'total_cents']],
        'payment.confirmed' => ['label' => 'Pagamento confirmado', 'fields' => ['payment_id', 'invoice_id', 'amount_cents']],
        'return.requested' => ['label' => 'Devolução pedida', 'fields' => ['return_id', 'order_id', 'number']],
    ];

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::EVENTS);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function publicData(string $type, array $payload): array
    {
        $fields = self::EVENTS[$type]['fields'] ?? [];

        return array_intersect_key($payload, array_flip($fields));
    }
}
