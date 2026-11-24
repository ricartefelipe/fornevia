<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Credit\Concerns;

use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditAccount;
use App\Support\Auth\Access;
use App\Support\Auth\SalesPortfolio;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Página da conta de crédito e suas seções (componentes filhos). A empresa
 * é identificada por propriedade bloqueada e recarregada no escopo do
 * tenant a cada ação; as Actions autorizam (credit.manage) de qualquer
 * forma, e a ocultação de formulários é apenas apresentação.
 *
 * @mixin Component
 */
trait InteractsWithCreditAccount
{
    /**
     * Evento emitido após qualquer alteração na conta (posição, exceções e
     * histórico são recarregados).
     */
    public const string UPDATED_EVENT = 'credit-account-updated';

    #[Locked]
    public string $buyerId = '';

    protected function bindBuyer(mixed $buyerId): void
    {
        // Fora da carteira do representante equivale a inexistente.
        $this->buyerId = app(SalesPortfolio::class)->findCompany($buyerId)->id;
    }

    protected function company(): BuyerCompany
    {
        return app(SalesPortfolio::class)->companies(BuyerCompany::query())->findOrFail($this->buyerId);
    }

    protected function account(): ?CreditAccount
    {
        return CreditAccount::query()->where('buyer_company_id', $this->buyerId)->first();
    }

    protected function canManage(): bool
    {
        return app(Access::class)->can(Permission::CreditManage);
    }

    /**
     * Valor em reais digitado em pt-BR ("1.234,56", "1234,56" ou "1234.56").
     */
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

    protected static function moneyInput(Money $money): string
    {
        return str_replace('.', ',', $money->toDecimalString());
    }

    /**
     * Associa a mensagem da regra de negócio ao campo do formulário.
     *
     * @param  array<string, string>  $fields  campo da regra => propriedade
     */
    protected function addViolation(BusinessRuleViolation $violation, array $fields, string $fallback): void
    {
        $field = $violation->details()['field'] ?? null;

        $this->addError(is_string($field) && isset($fields[$field]) ? $fields[$field] : $fallback, $violation->getMessage());
    }
}
