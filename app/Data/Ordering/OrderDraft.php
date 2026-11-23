<?php

declare(strict_types=1);

namespace App\Data\Ordering;

use App\Domain\Ordering\DocumentTotals;
use App\Enums\Ordering\OrderSource;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;

/**
 * Pedido a gravar pelo caminho do checkout (OrderPlacement): empresa,
 * solicitante, origem (carrinho ou versão de cotação), escolhas validadas,
 * totais e linhas calculados no servidor.
 */
final readonly class OrderDraft
{
    /**
     * @param  list<OrderLineDraft>  $lines
     */
    public function __construct(
        public BuyerCompany $buyer,
        public Membership $placedBy,
        public OrderSource $source,
        public ?string $sourceCartId,
        public ?string $sourceQuoteVersionId,
        public CheckoutSelection $selection,
        public ?string $purchaseOrderReference,
        public ?string $notes,
        public DocumentTotals $totals,
        public array $lines,
    ) {}
}
