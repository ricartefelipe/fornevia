<?php

declare(strict_types=1);

namespace App\Data\Pricing;

/**
 * Condição de pagamento: dias de vencimento de cada parcela a partir do
 * faturamento. À vista corresponde a uma única parcela no dia 0; a prazo
 * (requiresCredit) consome o crédito comercial da empresa compradora.
 */
final readonly class PaymentTermData
{
    /**
     * @param  list<int>  $installmentDays
     */
    public function __construct(
        public string $code,
        public string $name,
        public array $installmentDays,
        public bool $requiresCredit,
        public bool $isActive = true,
    ) {}
}
