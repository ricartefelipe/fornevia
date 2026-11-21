<?php

declare(strict_types=1);

/*
 * Processo worker dos testes de concorrência. Inicializa a aplicação de
 * forma independente, executa o cenário pedido e escreve o resultado em
 * JSON na última linha da saída padrão.
 *
 * Uso: php tests/Support/Concurrency/run-worker.php <cenário> <json>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\WorkerGate;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.connections.concurrency_gate' => config('database.connections.pgsql')]);

/** @var array<string, Closure(array<string, mixed>, WorkerGate): array<string, mixed>> $scenarios */
$scenarios = require __DIR__.'/scenarios.php';
$name = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

try {
    if (! isset($scenarios[$name])) {
        throw new InvalidArgumentException("Cenário desconhecido: {$name}");
    }

    $result = ['ok' => true, 'data' => $scenarios[$name]($args, new WorkerGate(DB::connection('concurrency_gate')))];
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
}

fwrite(STDOUT, PHP_EOL.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
exit(0);
