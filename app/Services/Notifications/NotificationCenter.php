<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\Identity\MembershipStatus;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Support\Clock\Clock;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Entrega de notificações (ADR-026), no contexto de sistema do tenant: uma
 * notificação por mensagem e associação ativa, nunca para quem causou o
 * evento. O e-mail sai depois do commit, só para notificação criada agora,
 * e-mail confirmado, pessoa ativa e preferência de e-mail ligada.
 */
final readonly class NotificationCenter
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  iterable<Membership>  $recipients
     * @return int notificações criadas
     */
    public function deliver(OutboxMessage $message, NotificationContent $content, iterable $recipients): int
    {
        $actor = $message->actorUserId();
        $seen = [];
        $created = 0;
        $now = $this->clock->now();

        foreach ($recipients as $membership) {
            if (isset($seen[$membership->id]) || $membership->status !== MembershipStatus::Active || $membership->user_id === $actor) {
                continue;
            }

            $seen[$membership->id] = true;
            $inserted = DB::selectOne(
                'insert into user_notifications (id, tenant_id, membership_id, user_id, outbox_message_id, kind, title, body, url, created_at)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 on conflict (tenant_id, outbox_message_id, membership_id) do nothing
                 returning id',
                [
                    (string) Str::uuid7(), $message->tenantId, $membership->id, $membership->user_id, $message->id,
                    $content->kind, mb_substr($content->title, 0, 160), mb_substr($content->body, 0, 500), $content->path, $now,
                ],
            );

            if ($inserted === null) {
                continue;
            }

            $created++;
            $this->mail($membership, $content);
        }

        return $created;
    }

    private function mail(Membership $membership, NotificationContent $content): void
    {
        $user = $membership->user;

        if ($user->email_verified_at === null || $user->disabled_at !== null) {
            return;
        }

        $enabled = DB::table('notification_settings')->where('membership_id', $membership->id)->value('email_enabled');

        if ($enabled === false) {
            return;
        }

        $organization = Organization::query()->whereKey($membership->tenant_id)->value('trade_name');

        Notification::route('mail', $user->email)->notify(new ActivityMail(
            (string) $organization,
            $content->title,
            $content->body,
            $content->actionLabel,
            url($content->path),
        ));
    }
}
