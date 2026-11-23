<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Outbox\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processo contínuo do relay da outbox (container `outbox`): publica lotes
 * até receber SIGTERM ou SIGINT, terminando a rodada em andamento, e limpa
 * periodicamente as mensagens publicadas além da retenção.
 */
final class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay
        {--once : Processa uma rodada e encerra}
        {--batch= : Mensagens por rodada}
        {--max-rounds=0 : Encerra depois deste número de rodadas (0 = sem limite)}';

    protected $description = 'Publica as mensagens da outbox nas filas dos consumidores';

    private bool $stopping = false;

    public function handle(OutboxRelay $relay): int
    {
        $owner = OutboxRelay::owner();
        $batch = $this->option('batch') !== null ? max(1, (int) $this->option('batch')) : null;
        $maxRounds = $this->option('once') ? 1 : max(0, (int) $this->option('max-rounds'));
        $idle = max(10, (int) config('outbox.idle_sleep_ms', 1000));
        $pruneEvery = max(60, (int) config('outbox.prune_every_seconds', 3600));
        $lastPrune = 0;
        $rounds = 0;
        $failures = 0;
        $totals = ['claimed' => 0, 'published' => 0, 'failed' => 0, 'dead' => 0];

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        while (! $this->stopping) {
            try {
                $result = $relay->runOnce($owner, $batch);

                foreach ($result as $key => $count) {
                    $totals[$key] += $count;
                }

                $failures = 0;

                if (time() - $lastPrune >= $pruneEvery) {
                    $pruned = $relay->prune();
                    $lastPrune = time();

                    if ($pruned > 0) {
                        Log::info('Mensagens publicadas da outbox removidas pela retenção.', ['removed' => $pruned]);
                    }
                }
            } catch (Throwable $e) {
                // Banco ou fila indisponível: registra e tenta de novo depois.
                $failures++;
                Log::error('Falha na rodada do relay da outbox.', ['owner' => $owner, 'consecutive_failures' => $failures, 'exception' => $e]);
                $result = ['claimed' => 0];
            }

            $rounds++;

            if ($maxRounds > 0 && $rounds >= $maxRounds) {
                break;
            }

            // Sem mensagens: espera antes da próxima rodada (um sinal recebido
            // durante a espera encerra o laço na volta).
            if ($result['claimed'] === 0) {
                usleep(self::pauseMs($idle, $failures) * 1000);
            }
        }

        $this->info("Reivindicadas: {$totals['claimed']}; publicadas: {$totals['published']}; com falha: {$totals['failed']}; sem novas tentativas: {$totals['dead']}.");

        return self::SUCCESS;
    }

    /**
     * Pausa antes da próxima rodada: a espera ociosa, dobrando a cada falha
     * seguida (banco ou fila indisponível), até 30 segundos.
     */
    public static function pauseMs(int $idleMs, int $consecutiveFailures): int
    {
        return (int) min(30_000, $idleMs * (2 ** min(15, max(0, $consecutiveFailures))));
    }
}
