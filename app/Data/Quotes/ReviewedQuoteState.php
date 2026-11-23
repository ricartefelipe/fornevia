<?php

declare(strict_types=1);

namespace App\Data\Quotes;

use App\Enums\Quotes\QuoteVersionStatus;
use App\Models\Quotes\QuoteVersion;
use Illuminate\Support\Collection;

/**
 * Estado das versões que a tela da equipe mostrou: a versão com o
 * comprador e a versão em preparo. Submeter, enviar ou retirar só vale se,
 * sob o lock da cotação, o estado ainda for esse (RN-040).
 */
final readonly class ReviewedQuoteState
{
    public function __construct(
        public ?string $withBuyerVersionId,
        public ?string $preparingVersionId,
    ) {}

    /**
     * @param  iterable<QuoteVersion>  $versions
     */
    public static function of(iterable $versions): self
    {
        $versions = Collection::make($versions);

        return new self(
            $versions->first(static fn (QuoteVersion $v): bool => $v->status === QuoteVersionStatus::Sent)?->id,
            $versions->first(static fn (QuoteVersion $v): bool => $v->status->isPreparing())?->id,
        );
    }

    public function equals(self $other): bool
    {
        return $this->withBuyerVersionId === $other->withBuyerVersionId && $this->preparingVersionId === $other->preparingVersionId;
    }
}
