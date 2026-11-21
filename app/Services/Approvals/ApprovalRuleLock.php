<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\DB;

/**
 * Lock consultivo das regras de aprovação de uma empresa (ADR-006).
 *
 * Criar, editar, ativar, desativar ou excluir regra toma o lock exclusivo;
 * escolher a regra de um pedido (submissão e reavaliação) toma o
 * compartilhado. A escolha nunca combina critérios de uma versão da regra
 * com níveis de outra, nem lê níveis no meio da regravação, e escolhas de
 * pedidos diferentes não esperam umas pelas outras.
 */
final readonly class ApprovalRuleLock
{
    public function __construct(private CurrentContext $context) {}

    public function exclusive(string $buyerCompanyId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$this->key($buyerCompanyId)]);
    }

    public function shared(string $buyerCompanyId): void
    {
        DB::select('select pg_advisory_xact_lock_shared(hashtextextended(?, 0))', [$this->key($buyerCompanyId)]);
    }

    private function key(string $buyerCompanyId): string
    {
        return implode('|', ['approval_rules', $this->context->tenantId(), $buyerCompanyId]);
    }
}
