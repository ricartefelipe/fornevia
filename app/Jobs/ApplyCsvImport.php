<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Identity\Membership;
use App\Models\Integrations\CsvImport;
use App\Models\Integrations\CsvImportRow;
use App\Services\Integrations\Imports\ImportKinds;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Aplica uma importação confirmada (RF-113) no contexto da associação de
 * quem a confirmou: as Actions conferem de novo as permissões e as regras.
 * Só as válidas: cada linha em transação própria (a recusada fica com o
 * motivo). Tudo ou nada: todas numa transação; uma recusa desfaz tudo.
 * Linha já aplicada não é aplicada de novo (reprocessamento seguro).
 */
final class ApplyCsvImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $importId,
        public readonly string $tenantId,
        public readonly string $membershipId,
    ) {}

    public function handle(TenantDatabase $db, ImportKinds $kinds, Access $access, Clock $clock): void
    {
        $membership = $db->run(ExecutionContext::system($this->tenantId), fn (): ?Membership => Membership::query()->with('role')->find($this->membershipId));

        if ($membership === null) {
            return;
        }

        $context = $membership->executionContext();
        $import = $db->run($context, function () use ($access, $membership): ?CsvImport {
            $access->bindMembership($membership);
            $import = CsvImport::query()->lockForUpdate()->find($this->importId);

            if ($import === null || ! in_array($import->status, ['queued', 'processing'], true)) {
                return null;
            }

            $import->forceFill(['status' => 'processing'])->save();

            return $import;
        });

        if ($import === null) {
            return;
        }

        $importer = $kinds->get($import->kind);
        $memo = [];
        $line = null;

        try {
            if ($import->policy === 'all_or_none') {
                $db->run($context, function () use ($access, $membership, $import, $importer, &$memo, &$line): void {
                    $access->bindMembership($membership);

                    foreach (CsvImportRow::query()->where('csv_import_id', $import->id)->where('status', 'valid')->orderBy('line')->get() as $row) {
                        $line = $row->line;
                        $row->forceFill(['status' => 'applied', 'entity_id' => $importer->apply($row->data, $memo)])->save();
                    }
                });
            } else {
                $ids = $db->run($context, fn (): array => CsvImportRow::query()->where('csv_import_id', $import->id)->where('status', 'valid')->orderBy('line')->pluck('id')->all());

                foreach ($ids as $id) {
                    $db->run($context, function () use ($access, $membership, $importer, $id, &$memo): void {
                        $access->bindMembership($membership);
                        $row = CsvImportRow::query()->lockForUpdate()->whereKey($id)->firstOrFail();

                        if ($row->status !== 'valid') {
                            return;
                        }

                        // O que a linha recusada deixou na memória (um produto
                        // criado e desfeito, por exemplo) não vale para as próximas.
                        $before = $memo;

                        try {
                            DB::transaction(fn () => $row->forceFill(['status' => 'applied', 'entity_id' => $importer->apply($row->data, $memo)])->save());
                        } catch (BusinessRuleViolation $e) {
                            $memo = $before;
                            $row->forceFill(['status' => 'skipped', 'errors' => [$e->getMessage()]])->save();
                        }
                    });
                }
            }

            $this->finish($db, $context, $access, $membership, $clock, null);
        } catch (Throwable $e) {
            $failure = $import->policy === 'all_or_none'
                ? ($e instanceof BusinessRuleViolation ? "Linha {$line}: {$e->getMessage()} Nada foi aplicado." : 'Falha ao aplicar a importação; nada foi aplicado.')
                : 'Falha ao aplicar a importação; as linhas marcadas como aplicadas foram gravadas.';
            $this->finish($db, $context, $access, $membership, $clock, $failure);

            if (! $e instanceof BusinessRuleViolation) {
                throw $e;
            }
        }
    }

    private function finish(TenantDatabase $db, ExecutionContext $context, Access $access, Membership $membership, Clock $clock, ?string $failure): void
    {
        $db->run($context, function () use ($access, $membership, $clock, $failure): void {
            $access->bindMembership($membership);
            $import = CsvImport::query()->lockForUpdate()->findOrFail($this->importId);
            $applied = CsvImportRow::query()->where('csv_import_id', $import->id)->where('status', 'applied')->count();
            $import->forceFill([
                'status' => $failure === null ? 'done' : 'failed',
                'rows_applied' => $applied,
                'failure' => $failure !== null ? mb_substr($failure, 0, 500) : null,
                'finished_at' => $clock->now(),
            ])->save();
        });
    }
}
