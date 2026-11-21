<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Data\Credit\CreditExceptionEntry;
use App\Data\Credit\CreditExceptionPeriod;
use App\Data\Credit\CreditLimitChangeEntry;
use App\Data\Credit\CreditMovementEntry;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Identity\Permission;
use App\Models\Audit\AuditLog;
use App\Models\Credit\CreditAccount;
use App\Models\Credit\CreditException;
use App\Models\Credit\CreditMovement;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Consultas do detalhe da conta de crédito (equipe com credit.view):
 * exceções de limite, movimentos do ledger e histórico de limite e
 * situação, paginados e com nomes de responsáveis resolvidos em lote.
 */
final readonly class CreditAccountRecords
{
    /** @var array<string, string> */
    private const array REFERENCES = [
        'order' => 'Pedido',
        'invoice' => 'Fatura',
        'payment' => 'Pagamento',
        'credit_note' => 'Crédito ao cliente',
    ];

    public function __construct(
        private Access $access,
        private Clock $clock,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CreditExceptionEntry>
     */
    public function exceptions(CreditAccount $account, int $perPage = 10, string $pageName = 'page'): LengthAwarePaginator
    {
        $this->access->authorize(Permission::CreditView);

        $page = CreditException::query()
            ->where('credit_account_id', $account->id)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: $pageName);

        $rows = $page->getCollection();
        $users = $this->userNames([
            ...$rows->pluck('granted_by_user_id')->all(),
            ...$rows->pluck('revoked_by_user_id')->all(),
        ]);
        $revoked = $rows->filter(static fn (CreditException $row): bool => $row->revoked_at !== null)->pluck('id')->all();
        $revocationReasons = $revoked === [] ? [] : AuditLog::query()
            ->where('action', 'credit_exception.revoked')
            ->where('subject_type', (new CreditException)->getMorphClass())
            ->whereIn('subject_id', $revoked)
            ->pluck('reason', 'subject_id')
            ->all();
        $now = $this->clock->now();

        return $page->through(static function (CreditException $row) use ($users, $revocationReasons, $now): CreditExceptionEntry {
            $revokedBy = $row->getAttribute('revoked_by_user_id');

            return new CreditExceptionEntry(
                $row->id,
                $row->amount_cents,
                CreditExceptionPeriod::fromInstants($row->valid_from, $row->valid_until),
                $row->reason,
                $users[$row->granted_by_user_id] ?? 'Não identificado',
                CreditExceptionEntry::stateAt($now, $row->valid_from, $row->valid_until, $row->revoked_at),
                $row->revoked_at,
                is_string($revokedBy) ? ($users[$revokedBy] ?? 'Não identificado') : null,
                is_string($revocationReasons[$row->id] ?? null) ? $revocationReasons[$row->id] : null,
            );
        });
    }

    /**
     * @return LengthAwarePaginator<int, CreditMovementEntry>
     */
    public function movements(CreditAccount $account, int $perPage = 15, string $pageName = 'page'): LengthAwarePaginator
    {
        $this->access->authorize(Permission::CreditView);

        $page = CreditMovement::query()
            ->where('credit_account_id', $account->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: $pageName);

        $rows = $page->getCollection();
        $users = $this->userNames($rows->pluck('actor_user_id')->all());
        $orderIds = $rows->where('reference_type', 'order')->pluck('reference_id')->unique()->values()->all();
        $orders = $orderIds === [] ? [] : Order::query()->whereIn('id', $orderIds)->pluck('number', 'id')->all();

        return $page->through(static function (CreditMovement $row) use ($users, $orders): CreditMovementEntry {
            $label = self::REFERENCES[$row->reference_type] ?? $row->reference_type;
            $number = $row->reference_type === 'order' ? ($orders[$row->reference_id] ?? null) : null;
            $createdAt = $row->getAttribute('created_at');

            return new CreditMovementEntry(
                $row->id,
                $row->type,
                $row->committed_delta,
                $row->receivable_delta,
                is_string($number) ? $label.' '.$number : $label,
                $row->reason,
                $row->actor_user_id !== null ? ($users[$row->actor_user_id] ?? 'Não identificado') : 'Sistema',
                $createdAt instanceof CarbonImmutable ? $createdAt : CarbonImmutable::parse((string) $createdAt),
            );
        });
    }

    /**
     * @return LengthAwarePaginator<int, CreditLimitChangeEntry>
     */
    public function limitChanges(CreditAccount $account, int $perPage = 10, string $pageName = 'page'): LengthAwarePaginator
    {
        $this->access->authorize(Permission::CreditView);

        $page = DB::table('credit_limit_changes')
            ->where('credit_account_id', $account->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'previous_limit_cents', 'new_limit_cents', 'previous_status', 'new_status', 'reason', 'changed_by_user_id', 'created_at'], $pageName);

        $users = $this->userNames($page->getCollection()->pluck('changed_by_user_id')->all());

        return $page->through(static function (object $record) use ($users): CreditLimitChangeEntry {
            $row = (array) $record;

            return new CreditLimitChangeEntry(
                (string) $row['id'],
                Money::ofCents((int) $row['previous_limit_cents']),
                Money::ofCents((int) $row['new_limit_cents']),
                CreditAccountStatus::from((string) $row['previous_status']),
                CreditAccountStatus::from((string) $row['new_status']),
                (string) $row['reason'],
                $users[(string) $row['changed_by_user_id']] ?? 'Não identificado',
                CarbonImmutable::parse((string) $row['created_at'], 'UTC'),
            );
        });
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<string, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, is_string(...))));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $names;
    }
}
