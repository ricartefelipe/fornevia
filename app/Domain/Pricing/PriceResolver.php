<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\PriceNotAvailable;
use App\Domain\Shared\Quantity;
use DateTimeImmutable;

/**
 * Precedência determinística (ADR-011):
 * 1. origem: negociada → cliente → grupo → padrão;
 * 2. maior prioridade;
 * 3. início de vigência mais recente;
 * 4. referência criada primeiro (UUIDv7 em ordem lexicográfica).
 *
 * Apenas candidatos vigentes na data participam. Dentro do vencedor, a
 * faixa por volume define o preço de todas as unidades.
 */
final class PriceResolver
{
    /**
     * @param  list<PriceCandidate>  $candidates
     */
    public function resolve(array $candidates, Quantity $quantity, DateTimeImmutable $on): ResolvedPrice
    {
        $valid = array_values(array_filter(
            $candidates,
            static fn (PriceCandidate $c): bool => $c->isValidOn($on),
        ));

        if ($valid === []) {
            throw new PriceNotAvailable('Não há preço vigente para este item.');
        }

        usort($valid, static fn (PriceCandidate $a, PriceCandidate $b): int => [
            $a->source->rank(),
            -$a->priority,
            -(int) $a->validFrom->format('Ymd'),
            $a->referenceId,
        ] <=> [
            $b->source->rank(),
            -$b->priority,
            -(int) $b->validFrom->format('Ymd'),
            $b->referenceId,
        ]);

        $winner = $valid[0];
        [$unitPrice, $tier] = $winner->unitPriceFor($quantity);

        return new ResolvedPrice($unitPrice, $winner->source, $winner->referenceId, $tier, $winner->minUnitPrice, $winner->version);
    }
}
