<?php

declare(strict_types=1);

namespace App\Enums\Quotes;

/**
 * Motivo de recusa ou encerramento da cotação.
 */
enum QuoteCloseReason: string
{
    case Price = 'price';
    case LeadTime = 'lead_time';
    case PaymentTerm = 'payment_term';
    case OtherSupplier = 'other_supplier';
    case NotNeeded = 'not_needed';
    case Other = 'other';
    case StaffClosed = 'staff_closed';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Preço',
            self::LeadTime => 'Prazo de entrega',
            self::PaymentTerm => 'Condição de pagamento',
            self::OtherSupplier => 'Comprou de outro fornecedor',
            self::NotNeeded => 'Não precisa mais',
            self::Other => 'Outro motivo',
            self::StaffClosed => 'Encerrada pelo distribuidor',
        };
    }

    /**
     * Motivos que o comprador escolhe ao recusar ou cancelar.
     *
     * @return list<self>
     */
    public static function forBuyer(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $r): bool => $r !== self::StaffClosed));
    }
}
