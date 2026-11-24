<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Parcelas da fatura pela condição de pagamento (RF-100): uma por prazo,
 * com vencimento na data de emissão mais os dias do prazo; o valor é
 * dividido em partes iguais e a diferença de centavos fica na última.
 */
final class InstallmentSchedule
{
    /**
     * @param  list<int>  $days  dias de vencimento a partir da emissão, em ordem
     * @return list<array{sequence: int, due_date: CarbonImmutable, amount: Money}>
     */
    public static function split(Money $total, array $days, CarbonImmutable $issueDate): array
    {
        if (! $total->isPositive()) {
            throw new InvalidArgumentException('A fatura precisa de valor positivo.');
        }

        $days = $days === [] ? [0] : array_values($days);
        $count = count($days);

        if ($total->cents < $count) {
            $days = [$days[$count - 1]];
            $count = 1;
        }

        $base = intdiv($total->cents, $count);
        $installments = [];

        foreach ($days as $index => $day) {
            $last = $index === $count - 1;
            $installments[] = [
                'sequence' => $index + 1,
                'due_date' => $issueDate->startOfDay()->addDays(max(0, $day)),
                'amount' => Money::ofCents($last ? $total->cents - $base * ($count - 1) : $base),
            ];
        }

        return $installments;
    }
}
