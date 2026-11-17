<?php

declare(strict_types=1);

/**
 * Verifica metas de cobertura a partir de um relatório Cobertura do PHPUnit.
 *
 * Metas (docs/REQUISITOS.md, seção de qualidade):
 *  - linhas >= 90% no núcleo de negócio (app/Domain) e casos de uso (app/Actions);
 *  - branches >= 80% nos caminhos críticos (ver CRITICAL_PATHS).
 *
 * Exclusões são explícitas e justificadas em EXCLUDED, nunca por padrão.
 *
 * Uso: php scripts/coverage-gate.php build/coverage/cobertura.xml
 */
const CORE_PATHS = ['app/Domain/', 'app/Actions/'];
const CRITICAL_PATHS = [
    'app/Domain/Pricing/',
    'app/Domain/Catalog/',
    'app/Domain/Ordering/',
    'app/Domain/Credit/',
    'app/Domain/Inventory/',
    'app/Domain/Approvals/',
    'app/Domain/Finance/',
    'app/Actions/Ordering/',
    'app/Actions/Inventory/',
    'app/Actions/Credit/',
];
const EXCLUDED = [
    // Nenhuma exclusão no momento.
];
const LINE_TARGET = 90.0;
const BRANCH_TARGET = 80.0;

$report = $argv[1] ?? 'build/coverage/cobertura.xml';

if (! is_file($report)) {
    fwrite(STDERR, "Relatório não encontrado: {$report}\n");
    exit(2);
}

$xml = simplexml_load_file($report);

if ($xml === false) {
    fwrite(STDERR, "Relatório inválido: {$report}\n");
    exit(2);
}

$sources = [];
foreach ($xml->sources->source ?? [] as $source) {
    $sources[] = rtrim((string) $source, '/');
}

$totals = ['core' => [0, 0], 'critical_lines' => [0, 0], 'critical_branches' => [0, 0]];

foreach ($xml->xpath('//class') ?: [] as $class) {
    // Os nomes de arquivo do relatório são relativos ao <source> (ex.:
    // /var/www/html/app); normaliza para caminhos a partir da raiz (app/...).
    $filename = (string) $class['filename'];
    $absolute = str_starts_with($filename, '/') ? $filename : ($sources[0] ?? '').'/'.$filename;

    if (preg_match('#(?:^|/)(app/.+)$#', $absolute, $match) !== 1) {
        continue;
    }

    $file = $match[1];

    if (in_array($file, EXCLUDED, true)) {
        continue;
    }

    $isCore = array_filter(CORE_PATHS, static fn (string $p): bool => str_starts_with($file, $p)) !== [];
    $isCritical = array_filter(CRITICAL_PATHS, static fn (string $p): bool => str_starts_with($file, $p)) !== [];

    if (! $isCore && ! $isCritical) {
        continue;
    }

    foreach ($class->lines->line ?? [] as $line) {
        $hit = (int) $line['hits'] > 0 ? 1 : 0;

        if ($isCore) {
            $totals['core'][0] += $hit;
            $totals['core'][1]++;
        }

        if ($isCritical) {
            $totals['critical_lines'][0] += $hit;
            $totals['critical_lines'][1]++;

            if ((string) $line['branch'] === 'true' && preg_match('/\((\d+)\/(\d+)\)/', (string) $line['condition-coverage'], $m) === 1) {
                $totals['critical_branches'][0] += (int) $m[1];
                $totals['critical_branches'][1] += (int) $m[2];
            }
        }
    }
}

// O núcleo existe no código mas não aparece no relatório: falha, para que
// um relatório incompleto nunca seja tomado como meta atingida.
$coreFiles = [];
foreach (CORE_PATHS as $path) {
    $directory = dirname(__DIR__).'/'.$path;
    if (is_dir($directory)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
                $coreFiles[] = $entry->getPathname();
            }
        }
    }
}

if ($coreFiles !== [] && $totals['core'][1] === 0) {
    fwrite(STDERR, 'O relatório não contém linhas do núcleo ('.count($coreFiles)." arquivos em app/Domain e app/Actions).\n");
    exit(1);
}

$percent = static fn (array $pair): ?float => $pair[1] === 0 ? null : round($pair[0] * 100 / $pair[1], 2);
$failures = [];

foreach ([
    ['Linhas no núcleo (Domain + Actions)', $percent($totals['core']), LINE_TARGET, $totals['core']],
    ['Linhas nos caminhos críticos', $percent($totals['critical_lines']), LINE_TARGET, $totals['critical_lines']],
    ['Branches nos caminhos críticos', $percent($totals['critical_branches']), BRANCH_TARGET, $totals['critical_branches']],
] as [$label, $value, $target, $pair]) {
    if ($value === null) {
        echo sprintf("%-40s sem código medido\n", $label);

        continue;
    }

    $ok = $value >= $target;
    echo sprintf("%-40s %6.2f%% (%d/%d) meta %.0f%% %s\n", $label, $value, $pair[0], $pair[1], $target, $ok ? 'OK' : 'ABAIXO');

    if (! $ok) {
        $failures[] = $label;
    }
}

exit($failures === [] ? 0 : 1);
