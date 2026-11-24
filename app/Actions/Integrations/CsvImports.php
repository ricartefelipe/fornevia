<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Enums\Identity\Permission;
use App\Jobs\ApplyCsvImport;
use App\Models\Integrations\CsvImport;
use App\Services\Audit\AuditRecorder;
use App\Services\Integrations\Imports\ImportKinds;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Integrations\Csv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importação CSV (RF-113), por quem tem integrations.manage e a permissão do
 * tipo: prévia (limites de tamanho e linhas, validação de cada linha,
 * duplicidade no arquivo e no cadastro, resultado por linha), confirmação
 * com a política escolhida (tudo ou nada recusa se houver linha inválida;
 * só as válidas aplica as demais) e aplicação assíncrona. Reprocessar não
 * repete: linha aplicada não é aplicada de novo.
 */
final readonly class CsvImports
{
    public const array POLICIES = ['valid_only', 'all_or_none'];

    public function __construct(
        private Access $access,
        private ImportKinds $kinds,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function preview(string $kind, string $policy, string $fileName, string $contents): CsvImport
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $importer = $this->kinds->get($kind);
        $this->access->authorize($importer->permission());

        if (! in_array($policy, self::POLICIES, true)) {
            throw new InvalidIntegration('Escolha a política de aplicação.', ['field' => 'policy']);
        }

        $rows = Csv::read($contents, $importer->columns(), (int) config('integrations.imports.max_bytes'), (int) config('integrations.imports.max_rows'));
        $errors = $importer->validate($rows);
        $invalid = count(array_filter($errors, static fn (array $e): bool => $e !== []));

        return DB::transaction(function () use ($kind, $policy, $fileName, $contents, $rows, $errors, $invalid): CsvImport {
            $import = new CsvImport;
            $import->forceFill([
                'kind' => $kind,
                'policy' => $policy,
                'status' => 'validated',
                'original_name' => mb_substr(basename($fileName), 0, 200),
                'file_hash' => hash('sha256', $contents),
                'rows_total' => count($rows),
                'rows_valid' => count($rows) - $invalid,
                'rows_invalid' => $invalid,
                'created_by_user_id' => $this->access->userId(),
            ])->save();

            $now = $this->clock->now();
            $insert = [];

            foreach ($rows as $line => $row) {
                $insert[] = [
                    'id' => (string) Str::uuid7(), 'tenant_id' => $import->tenant_id, 'csv_import_id' => $import->id, 'line' => $line,
                    'data' => json_encode($row, JSON_THROW_ON_ERROR), 'status' => ($errors[$line] ?? []) === [] ? 'valid' : 'invalid',
                    'errors' => json_encode($errors[$line] ?? [], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
                ];
            }

            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('csv_import_rows')->insert($chunk);
            }

            $this->audit->record('csv_import.previewed', $import, ['kind' => $kind, 'rows' => count($rows), 'invalid' => $invalid]);

            return $import;
        });
    }

    public function confirm(mixed $importId): CsvImport
    {
        $this->access->authorize(Permission::IntegrationsManage);

        return DB::transaction(function () use ($importId): CsvImport {
            $import = CsvImport::query()->lockForUpdate()->findOrFail(UntrustedId::uuid($importId, CsvImport::class));
            $this->access->authorize($this->kinds->get($import->kind)->permission());

            if ($import->status !== 'validated') {
                throw new InvalidIntegration('Esta importação já foi confirmada ou recusada.');
            }

            if ($import->policy === 'all_or_none' && $import->rows_invalid > 0) {
                $import->forceFill(['status' => 'rejected', 'finished_at' => $this->clock->now(), 'failure' => 'Há linhas inválidas e a política é tudo ou nada.'])->save();

                return $import;
            }

            $import->forceFill(['status' => 'queued', 'confirmed_at' => $this->clock->now()])->save();
            ApplyCsvImport::dispatch($import->id, $import->tenant_id, (string) $this->access->membership()->id)->onQueue('default')->afterCommit();
            $this->audit->record('csv_import.confirmed', $import, ['policy' => $import->policy]);

            return $import;
        });
    }
}
