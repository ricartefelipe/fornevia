<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use InvalidArgumentException;

/**
 * Desconto, em pontos-base, que leva o preço de tabela ao preço líquido
 * desejado (auxiliar do editor da proposta). O líquido aplicado é sempre o
 * de Money::discountedBy com o desconto devolvido: o menor desconto que
 * chega exatamente ao líquido desejado ou, sem desconto exato, o mais
 * próximo (desempate pelo menor desconto).
 */
final class DiscountForNetPrice
{
    public static function of(Money $unitPrice, Money $desiredNet): Percentage
    {
        if (! $unitPrice->isPositive() || ! $desiredNet->isPositive() || $desiredNet->greaterThan($unitPrice)) {
            throw new InvalidArgumentException('O preço líquido desejado deve ser positivo e não maior que o preço de tabela.');
        }

        // O líquido cai (ou se mantém) conforme o desconto sobe: busca o menor
        // desconto cujo líquido não passa do desejado.
        [$low, $high] = [0, 9_999];

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (self::net($unitPrice, $middle) <= $desiredNet->cents) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        if ($low === 0 || self::net($unitPrice, $low) === $desiredNet->cents) {
            return Percentage::ofBasisPoints($low);
        }

        // Sem desconto exato: o mais próximo, com desempate pelo menor.
        $above = self::net($unitPrice, $low - 1) - $desiredNet->cents;
        $below = $desiredNet->cents - self::net($unitPrice, $low);

        return Percentage::ofBasisPoints($above <= $below ? $low - 1 : $low);
    }

    private static function net(Money $unitPrice, int $basisPoints): int
    {
        return $unitPrice->discountedBy(Percentage::ofBasisPoints($basisPoints))->cents;
    }
}
