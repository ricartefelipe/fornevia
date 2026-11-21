<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use App\Enums\Pricing\PriceListScope;
use Carbon\CarbonImmutable;

/**
 * Cabeçalho de uma tabela de preço. Identificadores de grupo e empresa são
 * entrada não confiável: a Action os recarrega no escopo do tenant.
 */
final readonly class PriceListData
{
    public function __construct(
        public string $code,
        public string $name,
        public PriceListScope $scope,
        public CarbonImmutable $validFrom,
        public ?CarbonImmutable $validUntil = null,
        public int $priority = 0,
        public ?string $customerGroupId = null,
        public ?string $buyerCompanyId = null,
    ) {}
}
