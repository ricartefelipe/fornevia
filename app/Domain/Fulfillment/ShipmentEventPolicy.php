<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Fulfillment\ShipmentStatus;

/**
 * Efeito de um evento de transporte na remessa (RN-091): a situação só
 * avança, de expedida para entregue. Eventos repetidos, fora de ordem ou de
 * acompanhamento (em trânsito, saiu para entrega, ocorrência) ficam no
 * histórico sem mudar a situação.
 */
final class ShipmentEventPolicy
{
    /** Nova situação da remessa, ou null quando o evento não a muda. */
    public static function transition(ShipmentStatus $current, ShipmentEventKind $kind): ?ShipmentStatus
    {
        return $current === ShipmentStatus::Shipped && $kind === ShipmentEventKind::Delivered
            ? ShipmentStatus::Delivered
            : null;
    }

    /** Eventos só são aceitos para remessa já expedida. */
    public static function accepts(ShipmentStatus $current): bool
    {
        return in_array($current, [ShipmentStatus::Shipped, ShipmentStatus::Delivered], true);
    }
}
