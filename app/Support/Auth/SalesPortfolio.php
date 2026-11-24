<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Carteira do representante comercial: na equipe do distribuidor, o
 * representante vê apenas as empresas pelas quais responde hoje
 * (buyer_companies.sales_rep_membership_id) e os dados ligados a elas
 * (pedidos, cotações, crédito, condições negociadas, tabelas e frete por
 * empresa). Os demais papéis veem todas as empresas.
 *
 * A RLS não cobre esse recorte (app_sees_all_buyers() é verdadeiro para toda
 * a equipe); por isso toda consulta da equipe sobre empresas compradoras ou
 * dados delas passa por aqui. Comprador e cliente da API não têm recorte;
 * sem contexto ativo, a consulta é recusada.
 */
final readonly class SalesPortfolio
{
    public function __construct(private Access $access) {}

    /**
     * Associação cuja carteira limita as consultas, ou null sem recorte.
     */
    public function membershipId(): ?string
    {
        // Clientes da API (contexto de integração) não têm carteira; sem
        // contexto algum, falha fechado em vez de devolver tudo.
        if ($this->access->apiClientId() !== null && $this->access->membershipOrNull() === null) {
            return null;
        }

        $membership = $this->access->membership();

        if ($membership->kind !== MembershipKind::Staff) {
            return null;
        }

        return $membership->role?->key === SystemRole::SalesRep->value ? $membership->id : null;
    }

    public function isRestricted(): bool
    {
        return $this->membershipId() !== null;
    }

    /**
     * @template TBuilder of Builder<BuyerCompany>
     *
     * @param  TBuilder  $companies
     * @return TBuilder
     */
    public function companies(Builder $companies): Builder
    {
        $membershipId = $this->membershipId();

        if ($membershipId !== null) {
            $companies->where($companies->getModel()->qualifyColumn('sales_rep_membership_id'), $membershipId);
        }

        return $companies;
    }

    /**
     * Restringe consultas de dados ligados a uma empresa compradora.
     *
     * @template TModel of Model
     * @template TBuilder of Builder<TModel>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrict(Builder $query, string $column = 'buyer_company_id'): Builder
    {
        $membershipId = $this->membershipId();

        if ($membershipId !== null) {
            $query->whereIn(
                str_contains($column, '.') ? $column : $query->getModel()->qualifyColumn($column),
                BuyerCompany::query()->select('id')->where('sales_rep_membership_id', $membershipId),
            );
        }

        return $query;
    }

    /**
     * Como restrict(), mas mantém os registros sem empresa (tabelas de preço
     * gerais e por grupo, catálogos atribuídos a grupos).
     *
     * @template TModel of Model
     * @template TBuilder of Builder<TModel>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrictOptional(Builder $query, string $column = 'buyer_company_id'): Builder
    {
        $membershipId = $this->membershipId();

        if ($membershipId !== null) {
            $qualified = str_contains($column, '.') ? $column : $query->getModel()->qualifyColumn($column);
            $query->where(static fn (Builder $q) => $q->whereNull($qualified)
                ->orWhereIn($qualified, BuyerCompany::query()->select('id')->where('sales_rep_membership_id', $membershipId)));
        }

        return $query;
    }

    /**
     * Empresa pelo identificador recebido: fora do formato, de outro
     * distribuidor ou fora da carteira equivale a inexistente.
     */
    public function findCompany(mixed $companyId): BuyerCompany
    {
        return $this->companies(BuyerCompany::query())->findOrFail(UntrustedId::uuid($companyId, BuyerCompany::class));
    }

    public function covers(?string $companyId): bool
    {
        $membershipId = $this->membershipId();

        return $membershipId === null
            || ($companyId !== null && BuyerCompany::query()->whereKey($companyId)->where('sales_rep_membership_id', $membershipId)->exists());
    }
}
