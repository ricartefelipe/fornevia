<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Data\Catalog\Portal\QuantityFeedback;
use App\Domain\Catalog\PurchaseRule;
use App\Domain\Catalog\PurchaseRuleViolation;
use App\Domain\Shared\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Confere a quantidade digitada pelo comprador contra a regra de compra
 * do SKU e sugere a quantidade válida mais próxima (maior ou igual à
 * informada).
 *
 * Entrada em formato brasileiro: vírgula decimal e ponto como separador de
 * milhar ("1.200", "2,5", "1.250,75"). Ponto seguido de uma ou duas casas
 * ("2.5"), ou após zero inicial ("0.500"), também é aceito como decimal.
 */
final class PurchaseQuantityAdvisor
{
    public const int MAX_INPUT_LENGTH = 20;

    /** Maior quantidade aceita em uma linha (cabe em numeric(18,3) com folga). */
    public const string MAX_QUANTITY = PurchaseRule::MAX_QUANTITY;

    /**
     * Quantidade de referência do SKU: a menor quantidade válida de compra.
     */
    public function reference(PurchaseRule $rule): Quantity
    {
        return $rule->nearestValid($rule->minimum);
    }

    public function evaluate(PurchaseRule $rule, string $input, string $unitCode): QuantityFeedback
    {
        $input = mb_substr(trim($input), 0, self::MAX_INPUT_LENGTH + 1);
        $decimal = $this->parse($input);

        if ($decimal === null) {
            return new QuantityFeedback(
                input: $input,
                requested: null,
                valid: false,
                violations: [],
                messages: ['Informe a quantidade em números, por exemplo 12 ou 2,5.'],
                suggestion: null,
            );
        }

        if ($decimal->isNegative() && $decimal->abs()->isGreaterThan(BigDecimal::of(self::MAX_QUANTITY))) {
            // Negativo fora do intervalo: a recusa é a mesma de qualquer negativo.
            return new QuantityFeedback(
                input: $input,
                requested: null,
                valid: false,
                violations: [PurchaseRuleViolation::NotPositive],
                messages: $this->messages([PurchaseRuleViolation::NotPositive], $rule, $unitCode),
                suggestion: $this->reference($rule),
            );
        }

        if ($decimal->isGreaterThan(BigDecimal::of(self::MAX_QUANTITY))) {
            return new QuantityFeedback(
                input: $input,
                requested: null,
                valid: false,
                violations: [],
                messages: ['A quantidade informada excede o limite de '.Quantity::of(self::MAX_QUANTITY)->format()." {$unitCode} por item."],
                suggestion: null,
            );
        }

        $exceedsScale = $decimal->getScale() > Quantity::SCALE;
        $requested = Quantity::fromDecimal($decimal->toScale(Quantity::SCALE, RoundingMode::Ceiling));
        $violations = $rule->violations($requested);

        if ($exceedsScale && ! in_array(PurchaseRuleViolation::TooManyDecimals, $violations, true)) {
            array_unshift($violations, PurchaseRuleViolation::TooManyDecimals);
        }

        if ($violations === []) {
            return new QuantityFeedback($input, $requested, true, [], [], null);
        }

        $suggestion = $requested->isPositive() ? $rule->nearestValid($requested) : $this->reference($rule);

        return new QuantityFeedback(
            input: $input,
            requested: $exceedsScale ? null : $requested,
            valid: false,
            violations: $violations,
            messages: $this->messages($violations, $rule, $unitCode),
            suggestion: $rule->accepts($suggestion) ? $suggestion : null,
        );
    }

    /**
     * Converte a entrada em decimal sem passar por float; null quando não
     * é um número no formato aceito.
     */
    public function parse(string $input): ?BigDecimal
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($input));

        if ($value === '' || strlen($value) > self::MAX_INPUT_LENGTH) {
            return null;
        }

        $normalized = match (true) {
            preg_match('/^-?[1-9]\d{0,2}(\.\d{3})+(,\d+)?$/D', $value) === 1 => str_replace(['.', ','], ['', '.'], $value),
            preg_match('/^-?\d+(,\d+)?$/D', $value) === 1 => str_replace(',', '.', $value),
            preg_match('/^-?(\d+\.\d{1,2}|0\.\d+)$/D', $value) === 1 => $value,
            default => null,
        };

        if ($normalized === null) {
            return null;
        }

        try {
            $decimal = BigDecimal::of($normalized);
        } catch (MathException) {
            return null;
        }

        return $decimal->getScale() > 0 ? $decimal->strippedOfTrailingZeros() : $decimal;
    }

    /**
     * Com múltiplo unitário, a recusa por casas decimais já explica o
     * problema; a mensagem de múltiplo seria redundante.
     *
     * @param  list<PurchaseRuleViolation>  $violations
     * @return list<string>
     */
    private function messages(array $violations, PurchaseRule $rule, string $unitCode): array
    {
        $redundantMultiple = in_array(PurchaseRuleViolation::TooManyDecimals, $violations, true)
            && $rule->multiple->equals(Quantity::of(1));

        $messages = [];

        foreach ($violations as $violation) {
            if ($violation === PurchaseRuleViolation::NotMultiple && $redundantMultiple) {
                continue;
            }

            $messages[] = $this->message($violation, $rule, $unitCode);
        }

        return $messages;
    }

    private function message(PurchaseRuleViolation $violation, PurchaseRule $rule, string $unitCode): string
    {
        return match ($violation) {
            PurchaseRuleViolation::NotPositive => 'Informe uma quantidade maior que zero.',
            PurchaseRuleViolation::TooManyDecimals => $rule->decimals === 0
                ? "A unidade {$unitCode} não aceita casas decimais."
                : "A unidade {$unitCode} aceita no máximo {$rule->decimals} ".($rule->decimals === 1 ? 'casa decimal.' : 'casas decimais.'),
            PurchaseRuleViolation::BelowMinimum => "A quantidade mínima de compra é {$rule->minimum->format()} {$unitCode}.",
            PurchaseRuleViolation::NotMultiple => "Este item é vendido em múltiplos de {$rule->multiple->format()} {$unitCode}.",
        };
    }
}
