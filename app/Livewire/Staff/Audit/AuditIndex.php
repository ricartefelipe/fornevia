<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Audit;

use App\Models\Audit\AuditLog;
use App\Models\Identity\User;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Auditoria do distribuidor (audit.view): registros somente leitura, do
 * mais recente ao mais antigo, filtrados por ação (ou grupo de ações),
 * autor, registro afetado e período no fuso comercial. Valores de filtro
 * inválidos vindos da URL são ignorados.
 */
#[Layout('layouts.app')]
#[Title('Auditoria')]
final class AuditIndex extends Component
{
    use WithPagination;

    public const int PER_PAGE = 50;

    private const array FILTERS = ['action', 'actor', 'subject', 'dateFrom', 'dateUntil'];

    /** Ação exata ("order.confirmed") ou grupo ("order"). */
    #[Url(as: 'acao', except: '')]
    public string $action = '';

    /** Usuário autor (UUID). */
    #[Url(as: 'autor', except: '')]
    public string $actor = '';

    /** Registro afetado (UUID). */
    #[Url(as: 'registro', except: '')]
    public string $subject = '';

    #[Url(as: 'de', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'ate', except: '')]
    public string $dateUntil = '';

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.staff.audit.audit-index', [
            'logs' => $this->logs(),
            'actionOptions' => $this->actionOptions(),
            'actorOptions' => $this->actorOptions(),
            'filtered' => implode('', array_map(fn (string $f): string => trim((string) $this->{$f}), self::FILTERS)) !== '',
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    private function logs(): LengthAwarePaginator
    {
        $logs = AuditLog::query()->with('actor')->orderByDesc('created_at')->orderByDesc('id');
        $action = trim($this->action);

        if (preg_match('/^[a-z_]+(\.[a-z_]+)*$/D', $action) === 1) {
            $logs->where(static fn (Builder $q) => $q->where('action', $action)->orWhere('action', 'like', str_replace('_', '\\_', $action).'.%'));
        }

        if (Str::isUuid($this->actor)) {
            $logs->where('actor_user_id', strtolower($this->actor));
        }

        if (Str::isUuid($this->subject)) {
            $logs->where('subject_id', strtolower($this->subject));
        }

        $timezone = (string) config('app.business_timezone');

        if (($from = self::date($this->dateFrom)) !== null) {
            $logs->where('created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->format('Y-m-d H:i:sP'));
        }

        if (($until = self::date($this->dateUntil)) !== null) {
            $logs->where('created_at', '<', CarbonImmutable::parse($until, $timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:sP'));
        }

        return $logs->paginate(self::PER_PAGE);
    }

    /**
     * Grupos e ações já registrados no tenant.
     *
     * @return array<string, string>
     */
    private function actionOptions(): array
    {
        $options = [];

        foreach (AuditLog::query()->distinct()->orderBy('action')->limit(500)->pluck('action') as $action) {
            $group = Str::before((string) $action, '.');
            $options[$group] ??= $group.' (todas)';
            $options[(string) $action] = '— '.$action;
        }

        return $options;
    }

    /**
     * Usuários que aparecem como autores (até 200).
     *
     * @return array<string, string>
     */
    private function actorOptions(): array
    {
        $ids = AuditLog::query()->whereNotNull('actor_user_id')->distinct()->limit(200)->pluck('actor_user_id')->all();

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'email'])
            ->mapWithKeys(static fn (User $u): array => [$u->id => $u->name.' · '.$u->email])->all();
    }

    private static function date(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || $value < BusinessDate::EARLIEST || $value > BusinessDate::LATEST) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
