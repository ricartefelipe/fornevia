<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Enums\Pricing\PriceListScope;
use App\Models\Pricing\PriceList;
use App\Support\Auth\SalesPortfolio;
use App\Support\Clock\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Preço e custo opcionais da importação de catálogo: valores em reais
 * ("12,50", "1.234,56" ou "12.50"), tabela de preço pelo código ou a tabela
 * padrão vigente (na carteira de quem importa), e as permissões de quem
 * importa.
 */
final class ImportedCommercialData
{
    /** @var array<string, PriceList|null> */
    private array $lists;

    public function __construct(private bool $canPrice, private bool $canCost, private Clock $clock)
    {
        $this->lists = [];
    }

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
            } elseif ($this->priceList($listCode) === null) {
                $errors[] = $listCode === '' ? 'Não há tabela de preço padrão vigente: informe tabela_preco.' : 'Tabela de preço não encontrada ou arquivada.';
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
     * Tabela pelo código ou, sem código, a tabela padrão ativa e vigente de
     * maior prioridade; arquivada ou fora da carteira do representante não
     * serve. Resultado guardado por código para o arquivo inteiro.
     */
    public function priceList(string $code): ?PriceList
    {
        $code = mb_strtoupper(trim($code));

        if (! array_key_exists($code, $this->lists)) {
            $query = self::available();
            $today = $this->clock->now()->setTimezone((string) config('app.business_timezone'))->toDateString();

            $this->lists[$code] = $code !== ''
                ? $query->where('code', $code)->first()
                : $query->where('scope', PriceListScope::Default->value)->where('status', 'active')
                    ->where('valid_from', '<=', $today)
                    ->where(static fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
                    ->orderByDesc('priority')->orderByDesc('valid_from')->first();
        }

        return $this->lists[$code];
    }

    /** Tabela resolvida na prévia, conferida de novo na aplicação. */
    public static function priceListById(string $id): ?PriceList
    {
        return Str::isUuid($id) ? self::available()->whereKey($id)->first() : null;
    }

    /**
     * @return Builder<PriceList>
     */
    private static function available(): Builder
    {
        return app(SalesPortfolio::class)->restrictOptional(PriceList::query())->where('status', '!=', 'archived');
    }
}
