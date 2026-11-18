<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Identificadores vindos do cliente (parâmetros de ações Livewire,
 * formulários) são entrada não confiável: fora do formato UUID, equivalem
 * a registro inexistente, sem chegar ao banco.
 */
final class UntrustedId
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function uuid(mixed $value, string $model): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw (new ModelNotFoundException)->setModel($model);
        }

        // Forma canônica: o PostgreSQL aceita UUID em maiúsculas, mas comparações
        // na aplicação (listas, chaves de array) usam a forma minúscula.
        return strtolower($value);
    }
}
