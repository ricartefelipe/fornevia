<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Identity\Membership;
use App\Models\Integrations\CsvImport;
use App\Models\Integrations\CsvImportRow;
use App\Services\Integrations\Imports\ImportKind;
use App\Services\Integrations\Imports\ImportKinds;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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

    /** Abaixo do retry_after da fila Redis (330 s), para não ser entregue a outro worker enquanto roda. */
    public int $timeout = 300;

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

        // Cada transação roda no contexto e com as permissões de quem confirmou.
        $context = $membership->executionContext();
        $inContext = static fn (Closure $callback): mixed => $db->run($context, static function () use ($access, $membership, $callback): mixed {
            $access->bindMembership($membership);

            return $callback();
        });

        $import = $inContext(fn (): ?CsvImport => $this->start());

        if ($import === null) {
            return;
        }

        $importer = $kinds->get($import->kind);
        $line = null;

        try {
            if ($import->policy === 'all_or_none') {
                // $line por referência: a falha aponta a linha recusada.
                $inContext(function () use ($import, $importer, &$line): void {
                    $this->applyAll($import, $importer, $line);
                });
            } else {
                $this->applyEach($inContext, $import, $importer);
            }

            $inContext(fn () => $this->finish($clock, null));
        } catch (Throwable $e) {
            $inContext(fn () => $this->finish($clock, self::failure($import->policy, $e, $line)));

            if (! self::refusal($e)) {
                throw $e;
            }
        }
    }

    /**
     * Job interrompido (tempo esgotado, worker encerrado): a importação fica
     * como falha, e o reprocessamento aplica só as linhas ainda pendentes.
     */
    public function failed(?Throwable $e): void
    {
        app(TenantDatabase::class)->run(ExecutionContext::system($this->tenantId), function (): void {
            CsvImport::query()->whereKey($this->importId)->whereIn('status', ['queued', 'processing'])->update([
                'status' => 'failed',
                'failure' => 'A aplicação foi interrompida; reprocesse para aplicar as linhas pendentes.',
                'rows_applied' => CsvImportRow::query()->where('csv_import_id', $this->importId)->where('status', 'applied')->count(),
                'finished_at' => app(Clock::class)->now(),
            ]);
        });
    }

    /** Marca como em aplicação a importação na fila (ou retomada). */
    private function start(): ?CsvImport
    {
        $import = CsvImport::query()->lockForUpdate()->find($this->importId);

        if ($import === null || ! in_array($import->status, ['queued', 'processing'], true)) {
            return null;
        }

        $import->forceFill(['status' => 'processing'])->save();

        return $import;
    }

    /** Tudo ou nada: todas as linhas válidas na mesma transação. */
    private function applyAll(CsvImport $import, ImportKind $importer, ?int &$line): void
    {
        $memo = [];

        foreach (CsvImportRow::query()->where('csv_import_id', $import->id)->where('status', 'valid')->orderBy('line')->get() as $row) {
            $line = $row->line;
            $row->forceFill(['status' => 'applied', 'entity_id' => $importer->apply($row->data, $memo)])->save();
        }
    }

    /**
     * Só as válidas: cada linha na própria transação; a recusada fica com o motivo.
     *
     * @param  Closure(Closure(): mixed): mixed  $inContext
     */
    private function applyEach(Closure $inContext, CsvImport $import, ImportKind $importer): void
    {
        $memo = [];
        /** @var list<string> $ids */
        $ids = $inContext(static fn (): array => CsvImportRow::query()->where('csv_import_id', $import->id)->where('status', 'valid')->orderBy('line')->pluck('id')->all());

        foreach ($ids as $id) {
            $inContext(static function () use ($importer, $id, &$memo): void {
                $row = CsvImportRow::query()->lockForUpdate()->whereKey($id)->firstOrFail();

                if ($row->status !== 'valid') {
                    return;
                }

                // O que a linha recusada deixou na memória (um produto criado e
                // desfeito, por exemplo) não vale para as próximas.
                $before = $memo;

                try {
                    DB::transaction(static function () use ($row, $importer, &$memo): void {
                        $row->forceFill(['status' => 'applied', 'entity_id' => $importer->apply($row->data, $memo)])->save();
                    });
                } catch (BusinessRuleViolation|AuthorizationException $e) {
                    // Regra ou permissão de quem confirmou recusa só a linha.
                    $memo = $before;
                    $row->forceFill(['status' => 'skipped', 'errors' => [$e->getMessage()]])->save();
                }
            });
        }
    }

    /** Recusa esperada: regra de negócio ou permissão de quem confirmou. */
    private static function refusal(Throwable $e): bool
    {
        return $e instanceof BusinessRuleViolation || $e instanceof AuthorizationException;
    }

    private static function failure(string $policy, Throwable $e, ?int $line): string
    {
        if ($policy !== 'all_or_none') {
            return 'Falha ao aplicar a importação; as linhas marcadas como aplicadas foram gravadas.';
        }

        if (self::refusal($e)) {
            return "Linha {$line}: {$e->getMessage()} Nada foi aplicado.";
        }

        return 'Falha ao aplicar a importação; nada foi aplicado.';
    }

    private function finish(Clock $clock, ?string $failure): void
    {
        $import = CsvImport::query()->lockForUpdate()->findOrFail($this->importId);
        $applied = CsvImportRow::query()->where('csv_import_id', $import->id)->where('status', 'applied')->count();
        $import->forceFill([
            'status' => $failure === null ? 'done' : 'failed',
            'rows_applied' => $applied,
            'failure' => $failure !== null ? mb_substr($failure, 0, 500) : null,
            'finished_at' => $clock->now(),
        ])->save();
    }
}
