<?php

declare(strict_types=1);

namespace App\Actions\Catalog\Support;

use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Normalização de textos do catálogo (espaços, limites de tamanho) e
 * geração de slugs únicos no tenant.
 */
final class CatalogText
{
    /**
     * Texto obrigatório de uma linha: espaços repetidos são reduzidos a um.
     */
    public static function required(string $value, string $field, int $max, string $message): string
    {
        $normalized = self::squish($value);

        if ($normalized === '' || mb_strlen($normalized) > $max) {
            throw InvalidCatalogData::field($field, $message);
        }

        return $normalized;
    }

    /**
     * Texto opcional de uma linha; vazio equivale a nulo.
     */
    public static function optional(?string $value, string $field, int $max, string $message): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::squish($value);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $max) {
            throw InvalidCatalogData::field($field, $message);
        }

        return $normalized;
    }

    /**
     * Texto opcional de várias linhas (descrições): preserva quebras de linha
     * e remove caracteres de controle.
     */
    public static function multiline(?string $value, string $field, int $max, string $message): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $value)));

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $max) {
            throw InvalidCatalogData::field($field, $message);
        }

        return $normalized;
    }

    public static function slug(string $value, int $max): string
    {
        return trim(mb_substr(Str::slug($value), 0, $max), '-');
    }

    /**
     * Primeiro slug livre a partir da base ("base", "base-2", "base-3"...).
     * A consulta já vem restrita ao tenant (escopo e RLS).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function uniqueSlug(Builder $query, string $source, string $fallback, int $max, ?string $ignoreId = null): string
    {
        // Reserva espaço para o sufixo numérico.
        $base = self::slug($source, $max - 6);

        if ($base === '') {
            $base = $fallback;
        }

        $taken = $query
            ->where(static fn (Builder $q) => $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->when($ignoreId !== null, static fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = "{$base}-{$suffix}";

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    private static function squish(string $value): string
    {
        return trim((string) preg_replace('/[\s\p{C}]+/u', ' ', $value));
    }
}
