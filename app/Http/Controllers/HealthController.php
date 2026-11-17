<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Redis\RedisManager;
use Throwable;

/**
 * Liveness: o processo responde. Readiness: dependências disponíveis e
 * schema atualizado (a aplicação não deve receber tráfego com migrations
 * pendentes, pois elas rodam em processo separado).
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(DatabaseManager $db, RedisManager $redis, Migrator $migrator): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn (): bool => $db->connection()->selectOne('select 1 as ok')?->ok === 1),
            'migrations' => $this->check(static function () use ($migrator): bool {
                $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
                $ran = $migrator->getRepository()->getRan();

                return array_diff($files, $ran) === [];
            }),
            'redis' => $this->check(static fn (): bool => (bool) $redis->connection()->command('ping')),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json(
            ['status' => $ready ? 'ready' : 'unavailable', 'checks' => array_map(
                static fn (bool $ok): string => $ok ? 'ok' : 'fail',
                $checks,
            )],
            $ready ? 200 : 503,
        );
    }

    /**
     * @param  callable(): bool  $probe
     */
    private function check(callable $probe): bool
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
