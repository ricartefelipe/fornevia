<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\Identity\Membership;
use App\Models\Notifications\UserNotification;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Central de notificações da associação do contexto atual (ADR-026): lista,
 * leitura e preferência de e-mail. A RLS restringe às linhas da própria
 * pessoa; o filtro pela associação separa os contextos da mesma pessoa.
 */
final readonly class MyNotifications
{
    public const int PER_PAGE = 20;

    public function __construct(private Access $access, private Clock $clock) {}

    public function unreadCount(): int
    {
        return $this->query()->whereNull('read_at')->count();
    }

    /**
     * @return LengthAwarePaginator<int, UserNotification>
     */
    public function paginate(bool $onlyUnread, int $page = 1): LengthAwarePaginator
    {
        return $this->query()
            ->when($onlyUnread, static fn (Builder $query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: max(1, $page));
    }

    /** Marca como lida e devolve o caminho interno da notificação. */
    public function open(mixed $notificationId): string
    {
        $notification = $this->query()->whereKey(UntrustedId::uuid($notificationId, UserNotification::class))->firstOrFail();

        if ($notification->read_at === null) {
            $this->query()->whereKey($notification->id)->whereNull('read_at')->update(['read_at' => $this->clock->now()]);
        }

        return $notification->url;
    }

    public function markAllRead(): int
    {
        return $this->query()->whereNull('read_at')->update(['read_at' => $this->clock->now()]);
    }

    public function emailEnabled(): bool
    {
        return DB::table('notification_settings')->where('membership_id', $this->membership()->id)->value('email_enabled') !== false;
    }

    public function setEmailEnabled(bool $enabled): void
    {
        $membership = $this->membership();
        $now = $this->clock->now();

        DB::statement(
            'insert into notification_settings (id, tenant_id, membership_id, user_id, email_enabled, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, ?)
             on conflict (tenant_id, membership_id) do update set email_enabled = excluded.email_enabled, updated_at = excluded.updated_at',
            [(string) Str::uuid7(), $membership->tenant_id, $membership->id, $membership->user_id, $enabled, $now, $now],
        );
    }

    /**
     * @return Builder<UserNotification>
     */
    private function query(): Builder
    {
        $membership = $this->membership();

        return UserNotification::query()->where('membership_id', $membership->id)->where('user_id', $membership->user_id);
    }

    private function membership(): Membership
    {
        return $this->access->membership();
    }
}
