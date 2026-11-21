<?php

declare(strict_types=1);

namespace App\Data\Credit;

use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;

/**
 * Filtros da lista de contas de crédito por empresa compradora.
 *
 * - search: nome fantasia, razão social ou CNPJ;
 * - situation: situação da conta ou empresas sem conta (WITHOUT_ACCOUNT);
 * - availableBelow: apenas contas abertas com disponível menor que o valor.
 */
final readonly class CreditAccountFilters
{
    public const string WITHOUT_ACCOUNT = 'none';

    public function __construct(
        public string $search = '',
        public ?string $situation = null,
        public ?Money $availableBelow = null,
    ) {}

    /**
     * Situações aceitas no filtro: valor => rótulo.
     *
     * @return array<string, string>
     */
    public static function situations(): array
    {
        $options = [];

        foreach (CreditAccountStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options + [self::WITHOUT_ACCOUNT => 'Sem conta'];
    }

    public function hasAny(): bool
    {
        return trim($this->search) !== '' || $this->situation !== null || $this->availableBelow !== null;
    }
}
