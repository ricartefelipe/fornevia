<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Proxies confiáveis definidos em configuração (compatível com config:cache).
 */
final class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return null;
        }

        if ($configured === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
