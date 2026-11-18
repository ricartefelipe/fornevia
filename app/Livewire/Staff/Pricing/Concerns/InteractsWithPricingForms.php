<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing\Concerns;

use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\SkuStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use Brick\Math\Exception\MathException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Apoio comum às telas de preços: busca de empresas e SKUs no escopo do
 * tenant, conversão de valores digitados em pt-BR (sem float) e associação
 * das violações de regra de negócio aos campos do formulário.
 *
 * @mixin Component
 */
trait InteractsWithPricingForms
{
    /**
     * Empresas compradoras por nome fantasia, razão social ou CNPJ (no
     * máximo 25). A empresa já selecionada permanece entre as opções.
     *
     * @return Collection<int, BuyerCompany>
     */
    protected function searchCompanies(string $term, ?string $selectedId = null): Collection
    {
        $term = trim($term);
        $query = BuyerCompany::query()->orderBy('trade_name')->limit(25);

        if ($term !== '') {
            $like = '%'.self::escapeLike($term).'%';
            $digits = (string) preg_replace('/\D/', '', $term);

            $query->where(static function (Builder $q) use ($like, $digits): void {
                $q->where('trade_name', 'ilike', $like)->orWhere('legal_name', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $q->orWhere('document', 'like', '%'.$digits.'%');
                }
            });
        }

        $companies = $query->get();

        if ($selectedId !== null && Str::isUuid($selectedId) && ! $companies->contains('id', $selectedId)) {
            $selected = BuyerCompany::query()->find($selectedId);

            if ($selected !== null) {
                $companies->prepend($selected);
            }
        }

        return $companies;
    }

    /**
     * Opções de seleção de empresa: id => "Nome fantasia · CNPJ".
     *
     * @return array<string, string>
     */
    protected function companyOptions(string $term, ?string $selectedId = null): array
    {
        $options = [];

        foreach ($this->searchCompanies($term, $selectedId) as $company) {
            $options[$company->id] = $company->trade_name.' · '.Cnpj::format($company->document);
        }

        return $options;
    }

    /**
     * SKUs não arquivados por código ou nome do produto (no máximo 10).
     *
     * @return Collection<int, Sku>
     */
    protected function searchSkus(string $term): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $like = '%'.self::escapeLike($term).'%';

        return Sku::query()
            ->with(['product', 'salesUnit'])
            ->where('status', '<>', SkuStatus::Archived->value)
            ->where(static fn (Builder $q) => $q->where('code', 'ilike', $like)
                ->orWhereHas('product', static fn (Builder $p) => $p->where('name', 'ilike', $like)))
            ->orderBy('code')
            ->limit(10)
            ->get();
    }

    protected function selectedSku(?string $skuId): ?Sku
    {
        return $skuId !== null ? Sku::query()->with(['product', 'salesUnit'])->find($skuId) : null;
    }

    protected function moneyOrError(string $field, string $value): ?Money
    {
        try {
            return Money::parse($value);
        } catch (AmountOutOfRange) {
            $this->addError($field, 'Valor acima do limite suportado.');

            return null;
        } catch (InvalidArgumentException|MathException) {
            $this->addError($field, 'Informe um valor em reais, por exemplo 1.234,56.');

            return null;
        }
    }

    /**
     * Quantidade digitada em pt-BR: "1.000" é mil, "2,5" é dois e meio.
     */
    protected function quantityOrError(string $field, string $value): ?Quantity
    {
        try {
            return Quantity::parseLocalized($value);
        } catch (AmountOutOfRange) {
            $this->addError($field, 'Quantidade acima do limite suportado.');

            return null;
        } catch (InvalidArgumentException|MathException) {
            $this->addError($field, 'Informe uma quantidade com até 3 casas decimais, por exemplo 1.000 ou 2,5.');

            return null;
        }
    }

    protected static function moneyInput(Money $money): string
    {
        return str_replace('.', ',', $money->toDecimalString());
    }

    protected static function quantityInput(Quantity $quantity): string
    {
        return str_replace('.', ',', (string) $quantity->value->strippedOfTrailingZeros());
    }

    /**
     * Associa a mensagem da violação ao campo do formulário indicado em
     * details['field'] (quando mapeado) ou ao campo padrão.
     *
     * @param  array<string, string>  $fields  campo da regra => propriedade do componente
     */
    protected function addViolation(BusinessRuleViolation $e, array $fields, string $fallback): void
    {
        $field = $e->details()['field'] ?? null;

        $this->addError(is_string($field) && isset($fields[$field]) ? $fields[$field] : $fallback, $e->getMessage());
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
