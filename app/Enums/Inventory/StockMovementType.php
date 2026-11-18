<?php

declare(strict_types=1);

namespace App\Enums\Inventory;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case ReturnReceipt = 'return_receipt';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case Shipment = 'shipment';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Entrada',
            self::AdjustmentIn => 'Ajuste de entrada',
            self::AdjustmentOut => 'Ajuste de saída',
            self::TransferIn => 'Transferência recebida',
            self::TransferOut => 'Transferência enviada',
            self::ReturnReceipt => 'Entrada por devolução',
            self::Reservation => 'Reserva',
            self::ReservationRelease => 'Liberação de reserva',
            self::Shipment => 'Expedição',
        };
    }
}
