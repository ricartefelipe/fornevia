<?php

declare(strict_types=1);

namespace App\Actions\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
        $errors = [];
        $values = [];

        foreach ($query as $key => $value) {
            if (in_array($key, ['page', 'per_page'], true)) {
                continue;
            }

            if (! isset($filters[$key])) {
                $errors[$key] = ['Filtro não permitido neste recurso. Permitidos: '.implode(', ', array_keys($filters)).'.'];

                continue;
            }

            if (! is_string($value) || $value === '' || mb_strlen($value) > 100 || ! self::matches($filters[$key], $value)) {
                $errors[$key] = ['Valor inválido para o filtro.'];

                continue;
            }

            $values[$key] = $value;
        }

        foreach (['page', 'per_page'] as $key) {
            $value = $query[$key] ?? null;

            if ($value !== null && (! is_string($value) || preg_match('/^\d{1,6}$/D', $value) !== 1 || (int) $value < 1 || ($key === 'per_page' && (int) $value > self::MAX_PER_PAGE))) {
                $errors[$key] = [$key === 'per_page' ? 'Informe de 1 a '.self::MAX_PER_PAGE.' registros por página.' : 'Página inválida.'];
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

    private static function matches(string $rule, string $value): bool
    {
        return match (true) {
            $rule === 'uuid' => \Illuminate\Support\Str::isUuid($value),
            $rule === 'datetime' => strtotime($value) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1,
            $rule === 'string' => true,
            str_starts_with($rule, 'enum:') => in_array($value, explode('|', substr($rule, 5)), true),
            default => false,
        };
    }
}
