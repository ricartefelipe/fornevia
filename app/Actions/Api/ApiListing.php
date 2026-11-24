<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Support\Integrations\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Listagens da API v1 (RF-110): só os filtros declarados de cada recurso
 * (parâmetro desconhecido é erro de validação, para não ser ignorado em
 * silêncio), página e tamanho de página de 1 a 100 (padrão 25).
 */
final class ApiListing
{
    public const int MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $filters  nome => regra (uuid, date, datetime, string, enum:a|b)
     * @return array<string, string> filtros válidos informados
     */
    public static function filters(array $query, array $filters): array
    {
        $errors = self::pageErrors($query);
        $values = [];

        foreach ($query as $key => $value) {
            if (in_array($key, ['page', 'per_page'], true)) {
                continue;
            }

            $error = self::filterError((string) $key, $value, $filters);

            if ($error !== null) {
                $errors[$key] = [$error];
            } else {
                $values[(string) $key] = (string) $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, array $params): LengthAwarePaginator
    {
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : 25;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        return $query->paginate(min(self::MAX_PER_PAGE, max(1, $perPage)), ['*'], 'page', max(1, $page));
    }

    /**
     * @param  array<string, string>  $filters
     */
    private static function filterError(string $key, mixed $value, array $filters): ?string
    {
        if (! isset($filters[$key])) {
            return 'Filtro não permitido neste recurso. Permitidos: '.implode(', ', array_keys($filters)).'.';
        }

        $valid = is_string($value) && $value !== '' && mb_strlen($value) <= 100 && self::matches($filters[$key], $value);

        return $valid ? null : 'Valor inválido para o filtro.';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, list<string>>
     */
    private static function pageErrors(array $query): array
    {
        $errors = [];

        foreach (['page' => 'Página inválida.', 'per_page' => 'Informe de 1 a '.self::MAX_PER_PAGE.' registros por página.'] as $key => $message) {
            $value = $query[$key] ?? null;
            $max = $key === 'per_page' ? self::MAX_PER_PAGE : PHP_INT_MAX;

            if ($value !== null && (! is_string($value) || preg_match('/^\d{1,6}$/D', $value) !== 1 || (int) $value < 1 || (int) $value > $max)) {
                $errors[$key] = [$message];
            }
        }

        return $errors;
    }

    private static function matches(string $rule, string $value): bool
    {
        return match (true) {
            $rule === 'uuid' => Str::isUuid($value),
            $rule === 'datetime' => Iso8601::parse($value) !== null,
            $rule === 'string' => true,
            str_starts_with($rule, 'enum:') => in_array($value, explode('|', substr($rule, 5)), true),
            default => false,
        };
    }
}
