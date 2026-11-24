<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Integrations;

use App\Actions\Integrations\CsvImports;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Models\Integrations\CsvImport;
use App\Models\Integrations\CsvImportRow;
use App\Services\Integrations\Imports\ImportKinds;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Importação CSV (RF-113): envio do arquivo, prévia com o resultado de
 * cada linha, confirmação com a política escolhida e histórico. O envio
 * temporário só é aceito de quem pode gerenciar integrações.
 */
#[Layout('layouts.app')]
#[Title('Importação CSV')]
final class CsvImportsPage extends Component
{
    use WithFileUploads {
        _startUpload as private startTemporaryUpload;
        _finishUpload as private finishTemporaryUpload;
    }

    public const int PREVIEW_ROWS = 200;

    public string $kind = 'customers';

    public string $policy = 'valid_only';

    public ?TemporaryUploadedFile $file = null;

    #[Locked]
    public ?string $previewId = null;

    public string $message = '';

    public function _startUpload(mixed $name, mixed $fileInfo, mixed $isMultiple): void
    {
        $this->authorizeTemporaryUpload($name);
        $this->startTemporaryUpload($name, $fileInfo, $isMultiple);
    }

    public function _finishUpload(mixed $name, mixed $tmpPath, mixed $isMultiple, mixed $append = true): void
    {
        $this->authorizeTemporaryUpload($name);
        $this->finishTemporaryUpload($name, $tmpPath, $isMultiple, $append);
    }

    public function preview(CsvImports $imports): void
    {
        $this->resetErrorBag();
        $this->message = '';
        $this->previewId = null;
        $max = intdiv((int) config('integrations.imports.max_bytes'), 1024);
        $this->validate(['file' => ['required', 'file', 'max:'.$max]], [], ['file' => 'arquivo']);
        $file = $this->file;

        if (! $file instanceof TemporaryUploadedFile) {
            $this->addError('file', 'Selecione um arquivo CSV.');

            return;
        }

        try {
            $import = $imports->preview($this->kind, $this->policy, $file->getClientOriginalName(), (string) $file->get());
        } catch (BusinessRuleViolation $e) {
            $this->addError('file', $e->getMessage());

            return;
        } finally {
            $file->delete();
            $this->file = null;
        }

        $this->previewId = $import->id;
    }

    public function confirm(CsvImports $imports): void
    {
        $this->resetErrorBag();

        if ($this->previewId === null) {
            return;
        }

        try {
            $import = $imports->confirm($this->previewId);
        } catch (BusinessRuleViolation $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->previewId = null;
        $this->message = $import->status === 'rejected'
            ? 'Importação recusada: há linhas inválidas e a política é tudo ou nada. Corrija o arquivo e envie de novo.'
            : 'Importação confirmada; a aplicação segue em segundo plano e o resultado aparece no histórico.';
    }

    public function reprocess(string $importId, CsvImports $imports): void
    {
        $this->resetErrorBag();

        try {
            $imports->reprocess($importId);
        } catch (BusinessRuleViolation $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->message = 'Reprocessamento agendado; só as linhas ainda não aplicadas serão aplicadas.';
    }

    public function discard(): void
    {
        $this->previewId = null;
        $this->resetErrorBag();
    }

    public function open(string $importId): void
    {
        $this->previewId = CsvImport::query()->findOrFail(UntrustedId::uuid($importId, CsvImport::class))->id;
    }

    public function render(ImportKinds $kinds): View
    {
        $preview = $this->previewId !== null ? CsvImport::query()->find($this->previewId) : null;
        $options = [];
        $columns = [];

        foreach (array_keys(ImportKinds::CLASSES) as $key) {
            $kind = $kinds->get($key);
            $options[$key] = $kind->label();
            $columns[$key] = $kind->columns();
        }

        return view('livewire.staff.integrations.csv-imports-page', [
            'kindOptions' => $options,
            'columns' => $columns,
            'policyOptions' => ['valid_only' => 'Aplicar só as linhas válidas', 'all_or_none' => 'Tudo ou nada (recusar se houver linha inválida)'],
            'preview' => $preview,
            // Primeiro as linhas com problema, depois as demais, pela ordem do arquivo.
            'previewRows' => $preview !== null
                ? CsvImportRow::query()->where('csv_import_id', $preview->id)->orderByRaw("status in ('invalid', 'skipped') desc")->orderBy('line')->limit(self::PREVIEW_ROWS)->get()
                : collect(),
            'history' => CsvImport::query()->orderByDesc('created_at')->limit(20)->get(),
        ]);
    }

    private function authorizeTemporaryUpload(mixed $name): void
    {
        abort_unless($name === 'file' && app(Access::class)->can(Permission::IntegrationsManage), 403);
    }
}
