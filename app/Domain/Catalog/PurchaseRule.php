<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Quantity;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Regras de quantidade de um SKU na unidade de venda: mínimo, múltiplo e
 * casas decimais permitidas pela unidade. Ex.: vendido por unidade com
 * múltiplo 12 (caixa fechada) recusa 13 e sugere 24.
 */
final readonly class PurchaseRule
{
    /** Maior mínimo, múltiplo ou quantidade de uma linha (cabe com folga em numeric(18,3)). */
    public const string MAX_QUANTITY = '999999999';

    public function __construct(
        public Quantity $minimum,
        public Quantity $multiple,
        public int $decimals,
    ) {
        if (! $minimum->isPositive() || ! $multiple->isPositive()) {
            throw new InvalidArgumentException('Mínimo e múltiplo devem ser positivos.');
        }

        if ($decimals < 0 || $decimals > Quantity::SCALE) {
            throw new InvalidArgumentException('Casas decimais devem estar entre 0 e 3.');
        }
    }

    /**
     * @return list<PurchaseRuleViolation>
     */
    public function violations(Quantity $quantity): array
    {
        if (! $quantity->isPositive()) {
            return [PurchaseRuleViolation::NotPositive];
        }

        $violations = [];

        if ($quantity->decimalPlaces() > $this->decimals) {
            $violations[] = PurchaseRuleViolation::TooManyDecimals;
        }

        if ($quantity->lessThan($this->minimum)) {
            $violations[] = PurchaseRuleViolation::BelowMinimum;
        }

        if (! $quantity->isMultipleOf($this->multiple)) {
            $violations[] = PurchaseRuleViolation::NotMultiple;
        }

        return $violations;
    }

    public function accepts(Quantity $quantity): bool
    {
        return $this->violations($quantity) === [];
    }

    /**
     * Menor quantidade válida maior ou igual à informada (sugestão ao usuário):
     * o primeiro múltiplo a partir de max(quantidade, mínimo). Como o múltiplo
     * é arredondado para cima a partir de um valor já ≥ mínimo, o resultado
     * nunca fica abaixo do mínimo.
     */
    public function nearestValid(Quantity $quantity): Quantity
    {
        $target = $quantity->lessThan($this->minimum) ? $this->minimum : $quantity;
        $steps = $target->value->dividedBy($this->multiple->value, 0, RoundingMode::Ceiling);

        return Quantity::fromDecimal($steps->multipliedBy($this->multiple->value));
    }
}
