<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O produto (ou um de seus SKUs) é referenciado por custos, preços ou
 * documentos comerciais e, por isso, não pode ser excluído (RN-022).
 */
final class ProductHasHistory extends ConflictingState
{
    public static function create(): self
    {
        return new self(
            'O produto possui histórico (custos, preços ou documentos comerciais) e não pode ser excluído. Arquive-o para retirá-lo do catálogo.',
            ['recommendation' => 'archive'],
        );
    }

    public function errorCode(): string
    {
        return 'product_has_history';
    }
}
