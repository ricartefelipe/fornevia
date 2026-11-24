<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegration;

/**
 * Proteção contra SSRF nos webhooks enviados (RF-111): só http(s), sem
 * credenciais na URL, HTTPS fora do desenvolvimento e destino que resolve
 * apenas para endereços públicos (sem rede privada, loopback, link-local
 * ou reservados), salvo configuração explícita. A entrega conecta no
 * endereço conferido aqui (sem nova resolução de DNS no meio do caminho) e
 * não segue redirecionamentos.
 */
final class WebhookUrlGuard
{
    /**
     * @return array{host: string, port: int, ip: string}
     */
    public static function resolve(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || mb_strlen($url) > 500) {
            throw new InvalidIntegration('Informe uma URL http(s) válida, sem usuário e senha, de até 500 caracteres.', ['field' => 'url']);
        }

        if ($scheme === 'http' && ! (bool) config('integrations.webhooks.allow_http', false)) {
            throw new InvalidIntegration('Use HTTPS no endereço do webhook.', ['field' => 'url']);
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $hostIp = trim($host, '[]');
        $ips = filter_var($hostIp, FILTER_VALIDATE_IP) !== false ? [$hostIp] : self::lookup($host);

        if ($ips === []) {
            throw new InvalidIntegration('O endereço do webhook não foi encontrado (DNS).', ['field' => 'url']);
        }

        if (! (bool) config('integrations.webhooks.allow_private_networks', false)) {
            foreach ($ips as $ip) {
                if (! self::isPublic($ip)) {
                    throw new InvalidIntegration('O endereço do webhook aponta para uma rede interna, o que não é permitido.', ['field' => 'url']);
                }
            }
        }

        return ['host' => $hostIp, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Endereço global (RFC 6890): fora de rede privada, loopback,
     * link-local, CGNAT (100.64.0.0/10), testes de desempenho e demais
     * faixas reservadas. IPv6 que embute um IPv4 (mapeado ::ffff:0:0/96,
     * NAT64 64:ff9b::/96 e 6to4 2002::/16) vale pelo IPv4 embutido.
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $binary = inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16) {
            $embedded = match (true) {
                str_starts_with($binary, str_repeat("\0", 10)."\xff\xff") => substr($binary, 12, 4),
                str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($binary, 12, 4),
                str_starts_with($binary, "\x20\x02") => substr($binary, 2, 4),
                default => null,
            };

            if ($embedded !== null) {
                $ipv4 = inet_ntop($embedded);

                return $ipv4 !== false && self::isPublic($ipv4);
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];

        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
