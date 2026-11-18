<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Idempotência persistida no PostgreSQL (ADR-007).
 *
 * A chave é registrada na mesma transação do efeito. Requisições
 * concorrentes com a mesma chave esperam no índice único até a primeira
 * terminar: se ela confirmou, a segunda recebe o mesmo resultado; se ela
 * desfez, a segunda executa normalmente. Mesma chave com conteúdo diferente
 * gera IdempotencyKeyReused. Chave expirada pode ser reutilizada, mas as
 * demais proteções (ex.: unique por carrinho) continuam valendo.
 */
final readonly class IdempotencyGuard
{
    public function __construct(
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  conteúdo que identifica a solicitação
     * @param  Closure(): array{resource_type: string, resource_id: string, response: array<string, mixed>}  $work
     */
    public function run(string $scope, string $key, array $payload, Closure $work, int $ttlHours = 24): IdempotentResult
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $key) !== 1) {
            throw new InvalidIdempotencyKey('Chave de idempotência inválida (8 a 100 caracteres alfanuméricos, ponto, hífen, dois-pontos ou sublinhado).');
        }

        $execution = $this->context->require();
        $actor = $execution->userId ?? $execution->apiClientId ?? throw new RuntimeException('Idempotência exige um ator identificado.');
        $fingerprint = self::fingerprint($payload);
        $now = $this->clock->now();

        return DB::transaction(function () use ($scope, $key, $actor, $fingerprint, $now, $ttlHours, $work, $execution): IdempotentResult {
            $inserted = DB::selectOne(
                'insert into idempotency_keys (id, tenant_id, scope, actor, key, fingerprint, created_at, expires_at)
                 values (?, ?, ?, ?, ?, ?, ?, ?)
                 on conflict (tenant_id, scope, actor, key) do nothing
                 returning id',
                [(string) Str::uuid7(), $execution->requireTenantId(), $scope, $actor, $key, $fingerprint, $now, $now->addHours($ttlHours)],
            );

            if ($inserted === null) {
                $existing = DB::selectOne(
                    'select id, fingerprint, resource_type, resource_id, response, expires_at
                       from idempotency_keys
                      where tenant_id = ? and scope = ? and actor = ? and key = ?
                      for update',
                    [$execution->requireTenantId(), $scope, $actor, $key],
                );

                if ($existing === null) {
                    throw new RuntimeException('Registro de idempotência não encontrado após conflito.');
                }

                $expired = $now->greaterThanOrEqualTo((string) $existing->expires_at);

                if (! $expired && $existing->fingerprint !== $fingerprint) {
                    throw new IdempotencyKeyReused('Esta chave de idempotência já foi usada com outro conteúdo.');
                }

                if (! $expired && $existing->resource_id !== null) {
                    /** @var array<string, mixed> $response */
                    $response = json_decode((string) $existing->response, true, flags: JSON_THROW_ON_ERROR);

                    return new IdempotentResult(true, (string) $existing->resource_type, (string) $existing->resource_id, $response);
                }

                DB::update(
                    'update idempotency_keys set fingerprint = ?, resource_type = null, resource_id = null, response = null,
                            created_at = ?, expires_at = ? where id = ?',
                    [$fingerprint, $now, $now->addHours($ttlHours), $existing->id],
                );
                $keyId = (string) $existing->id;
            } else {
                $keyId = (string) $inserted->id;
            }

            $result = $work();

            DB::update(
                'update idempotency_keys set resource_type = ?, resource_id = ?, response = ?::jsonb where id = ?',
                [$result['resource_type'], $result['resource_id'], json_encode($result['response'], JSON_THROW_ON_ERROR), $keyId],
            );

            return new IdempotentResult(false, $result['resource_type'], $result['resource_id'], $result['response']);
        });
    }

    /**
     * SHA-256 do conteúdo canônico (chaves ordenadas recursivamente).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
