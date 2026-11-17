<?php

declare(strict_types=1);

namespace App\Enums\Platform;

/**
 * Módulos comercializáveis. O núcleo (catálogo, preços, pedidos, estoque e
 * crédito) faz parte de todos os planos.
 */
enum PlatformModule: string
{
    case Quotes = 'quotes';
    case Approvals = 'approvals';
    case Returns = 'returns';
    case Imports = 'imports';
    case Api = 'api';
    case Webhooks = 'webhooks';

    public function label(): string
    {
        return match ($this) {
            self::Quotes => 'Cotações e negociação',
            self::Approvals => 'Aprovações e orçamentos',
            self::Returns => 'Devoluções',
            self::Imports => 'Importação CSV',
            self::Api => 'API de integração',
            self::Webhooks => 'Webhooks',
        };
    }
}
