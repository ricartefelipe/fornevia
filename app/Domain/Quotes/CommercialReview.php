<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

use App\Domain\Pricing\DiscountViolation;
use App\Domain\Shared\Percentage;

/**
 * Avaliação comercial de uma versão (RN-041, RN-032, RN-033): violações de
 * todas as linhas e maior desconto. Qualquer violação exige aprovação
 * comercial de quem tem limite de desconto maior ou igual ao maior
 * desconto da versão; o autor só se aprova na submissão, com permissão de
 * aprovar e limite suficiente.
 */
final readonly class CommercialReview
{
    /**
     * @param  list<CommercialReviewLine>  $lines
     */
    public function __construct(
        public Percentage $authorLimit,
        public array $lines,
    ) {}

    public function maxDiscount(): Percentage
    {
        $max = 0;

        foreach ($this->lines as $line) {
            $max = max($max, $line->evaluation->discount->basisPoints);
        }

        return Percentage::ofBasisPoints($max);
    }

    /**
     * União das violações, na ordem de declaração.
     *
     * @return list<DiscountViolation>
     */
    public function violations(): array
    {
        $found = [];

        foreach ($this->lines as $line) {
            foreach ($line->evaluation->violations as $violation) {
                $found[$violation->value] = true;
            }
        }

        return array_values(array_filter(DiscountViolation::cases(), static fn (DiscountViolation $v): bool => isset($found[$v->value])));
    }

    public function requiresApproval(): bool
    {
        return $this->violations() !== [];
    }

    /**
     * Quem tem este limite de desconto pode aprovar a versão?
     */
    public function coveredBy(Percentage $deciderLimit): bool
    {
        return $deciderLimit->basisPoints >= $this->maxDiscount()->basisPoints;
    }

    /**
     * O autor pode aprovar a própria exceção na submissão?
     */
    public function selfApprovable(bool $authorCanApprove): bool
    {
        return $authorCanApprove && $this->coveredBy($this->authorLimit);
    }
}
