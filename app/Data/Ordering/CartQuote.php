<?php

declare(strict_types=1);

namespace App\Data\Ordering;

use App\Domain\Ordering\DocumentTotals;

final readonly class CartQuote
{
    /**
     * @param  list<CartLineQuote>  $lines
     */
    public function __construct(
        public array $lines,
        public DocumentTotals $totals,
    ) {}

    public function isReady(): bool
    {
        if ($this->lines === []) {
            return false;
        }

        foreach ($this->lines as $line) {
            if (! $line->isReady()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, list<string>> problemas por SKU
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->lines as $line) {
            if (! $line->isReady()) {
                $problems[$line->skuCode] = $line->problems;
            }
        }

        return $problems;
    }
}
