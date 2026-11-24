<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Support\Observability\Metrics;
use App\Support\Outbox\OutboxRelay;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Exposição Prometheus (formato texto 0.0.4) das métricas operacionais
 * (RQ-121): contadores acumulados no Redis e medidas lidas na hora (tamanho
 * das filas, outbox pendente, idade da mensagem pendente mais antiga, sem
 * novas tentativas, jobs com falha). Sem dados de tenant nos rótulos.
 */
final readonly class MetricsSnapshot
{
    private const array HELP = [
        'http_requests_total' => ['counter', 'Requisições atendidas por tipo e classe de status.'],
        'http_request_duration_seconds' => ['histogram', 'Duração das requisições por tipo, em segundos.'],
        'db_conflicts_total' => ['counter', 'Conflitos de concorrência no banco (deadlock, serialização, lock indisponível).'],
        'jobs_failed_total' => ['counter', 'Jobs que falharam, por fila.'],
        'queue_size' => ['gauge', 'Jobs aguardando, por fila.'],
        'outbox_pending' => ['gauge', 'Mensagens pendentes na outbox.'],
        'outbox_oldest_pending_age_seconds' => ['gauge', 'Idade da mensagem pendente mais antiga da outbox, em segundos.'],
        'outbox_dead' => ['gauge', 'Mensagens da outbox sem novas tentativas.'],
        'failed_jobs' => ['gauge', 'Jobs com falha guardados para reenvio.'],
        'metrics_collection_errors' => ['gauge', 'Medidas que não puderam ser lidas nesta coleta.'],
    ];

    public function __construct(
        private Metrics $metrics,
        private OutboxRelay $outbox,
        private QueueFactory $queues,
        private DatabaseManager $db,
    ) {}

    public function render(): string
    {
        $series = [];
        $errors = 0;

        try {
            foreach ($this->metrics->all() as $key => $value) {
                [$name, $labels] = array_pad(explode('|', $key, 2), 2, '');
                $series[$name][] = [$labels, $value];
            }
        } catch (Throwable) {
            $errors++;
        }

        $series = self::completeHistograms($series);

        foreach ($this->gauges($errors) as $name => $values) {
            $series[$name] = $values;
        }

        $series['metrics_collection_errors'] = [['', (float) $errors]];

        return $this->format($series);
    }

    /**
     * Todo limite configurado aparece em cada tipo com contagem (zero se
     * nenhuma requisição caiu nele), como o Prometheus espera de um histograma.
     *
     * @param  array<string, list<array{0: string, 1: float}>>  $series
     * @return array<string, list<array{0: string, 1: float}>>
     */
    private static function completeHistograms(array $series): array
    {
        $existing = [];

        foreach ($series['http_request_duration_seconds_bucket'] ?? [] as [$labels]) {
            $existing[$labels] = true;
        }

        $limits = [...array_map(static fn (mixed $b): string => Metrics::number((float) $b), (array) config('observability.latency_buckets')), '+Inf'];

        foreach ($series['http_request_duration_seconds_count'] ?? [] as [$labels]) {
            foreach ($limits as $limit) {
                $bucket = $labels.',le='.$limit;

                if (! isset($existing[$bucket])) {
                    $series['http_request_duration_seconds_bucket'][] = [$bucket, 0.0];
                }
            }
        }

        return $series;
    }

    /**
     * @return array<string, list<array{0: string, 1: float}>>
     */
    private function gauges(int &$errors): array
    {
        $gauges = [];

        foreach ((array) config('observability.queues') as $queue) {
            try {
                $gauges['queue_size'][] = ['queue='.Metrics::label((string) $queue), (float) $this->queues->connection()->size((string) $queue)];
            } catch (Throwable) {
                $errors++;
            }
        }

        try {
            $stats = $this->outbox->stats();
            $gauges['outbox_pending'] = [['', (float) $stats['pending']]];
            $gauges['outbox_oldest_pending_age_seconds'] = [['', (float) ($stats['oldest_pending_seconds'] ?? 0)]];
            $gauges['outbox_dead'] = [['', (float) $stats['dead']]];
        } catch (Throwable) {
            $errors++;
        }

        try {
            $gauges['failed_jobs'] = [['', (float) $this->db->connection()->table((string) config('queue.failed.table', 'failed_jobs'))->count()]];
        } catch (Throwable) {
            $errors++;
        }

        return $gauges;
    }

    /**
     * @param  array<string, list<array{0: string, 1: float}>>  $series
     */
    private function format(array $series): string
    {
        $lines = [];

        foreach (self::HELP as $family => [$type, $help]) {
            $members = array_filter($series, static fn (string $name): bool => $name === $family || str_starts_with($name, $family.'_') && $type === 'histogram', ARRAY_FILTER_USE_KEY);

            if ($members === []) {
                continue;
            }

            $lines[] = "# HELP fornevia_{$family} {$help}";
            $lines[] = "# TYPE fornevia_{$family} {$type}";
            ksort($members);

            foreach ($members as $name => $values) {
                usort($values, static fn (array $a, array $b): int => strnatcmp($a[0], $b[0]));

                foreach ($values as [$labels, $value]) {
                    $lines[] = 'fornevia_'.$name.self::labels($labels).' '.Metrics::number($value);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function labels(string $labels): string
    {
        if ($labels === '') {
            return '';
        }

        $pairs = array_map(static function (string $pair): string {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            return $key.'="'.$value.'"';
        }, explode(',', $labels));

        return '{'.implode(',', $pairs).'}';
    }
}
