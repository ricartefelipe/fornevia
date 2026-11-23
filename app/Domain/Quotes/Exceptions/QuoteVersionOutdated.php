<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A versão exibida não é mais a vigente (outra versão foi enviada, retirada ou a reserva terminou): a operação não vale para o que não foi visto (RN-040).
 */
final class QuoteVersionOutdated extends ConflictingState
{
    public function errorCode(): string
    {
        return 'quote_version_outdated';
    }
}
