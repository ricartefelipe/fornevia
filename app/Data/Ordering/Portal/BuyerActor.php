<?php

declare(strict_types=1);

namespace App\Data\Ordering\Portal;

use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;

/**
 * Associação de comprador do contexto atual e a empresa dela, já
 * conferidas (permissão, tipo de contexto e empresa da sessão).
 */
final readonly class BuyerActor
{
    public function __construct(
        public Membership $membership,
        public BuyerCompany $company,
    ) {}
}
