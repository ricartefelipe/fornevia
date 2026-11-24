<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Enums\Pricing\PriceListScope;
use App\Models\Pricing\PriceList;
use InvalidArgumentException;

/**
 * Preço e custo opcionais da importação de catálogo: valores em reais
 * ("12,50", "1.234,56" ou "12.50"), tabela de preço pelo código ou a tabela
 * padrão ativa, e as permissões de quem importa.
 */
final readonly class ImportedCommercialData
{
    public function __construct(private bool $canPrice, private bool $canCost) {}

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function errors(array $row): array
    {
        $errors = [];
        $price = trim($row['preco'] ?? '');
        $cost = trim($row['custo'] ?? '');
        $listCode = trim($row['tabela_preco'] ?? '');

        if ($price !== '') {
            if (! $this->canPrice) {
                $errors[] = 'Sem permissão para definir preço (pricing.manage).';
            } elseif (! (self::money($price)?->isPositive() ?? false)) {
                $errors[] = 'Preço inválido (valor positivo, ex.: 12,50).';
            } elseif (self::priceList($listCode) === null) {
                $errors[] = $listCode === '' ? 'Não há tabela de preço padrão ativa: informe tabela_preco.' : 'Tabela de preço não encontrada ou arquivada.';
            }
        } elseif ($listCode !== '') {
            $errors[] = 'Tabela de preço informada sem preço.';
        }

        if ($cost !== '') {
            if (! $this->canCost) {
                $errors[] = 'Sem permissão para informar custo (catalog.view_costs).';
            } elseif (self::money($cost) === null || self::money($cost)->isNegative()) {
                $errors[] = 'Custo inválido (valor zero ou positivo, ex.: 8,90).';
            }
        }

        return $errors;
    }

    public static function money(string $value): ?Money
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Money::parse($value);
        } catch (InvalidArgumentException|AmountOutOfRange) {
            return null;
        }
    }

    /**
     * Tabela pelo código (sem diferenciar maiúsculas) ou, sem código, a
     * tabela padrão ativa de maior prioridade; arquivada não serve.
     */
    public static function priceList(string $code): ?PriceList
    {
        $code = trim($code);
        $query = PriceList::query()->where('status', '!=', 'archived');

        return $code !== ''
            ? $query->whereRaw('upper(code) = ?', [mb_strtoupper($code)])->first()
            : $query->where('scope', PriceListScope::Default->value)->where('status', 'active')->orderByDesc('priority')->orderByDesc('valid_from')->first();
    }
}
