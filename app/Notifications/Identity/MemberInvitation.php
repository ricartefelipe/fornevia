<?php

declare(strict_types=1);

namespace App\Notifications\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de convite. Enviado somente após o commit da transação que
 * criou o convite.
 */
final class MemberInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $roleName,
        public readonly string $acceptUrl,
        public readonly CarbonImmutable $expiresAt,
    ) {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Convite para acessar {$this->organizationName} no Fornevia")
            ->greeting('Olá!')
            ->line("{$this->inviterName} convidou você para acessar {$this->organizationName} como {$this->roleName}.")
            ->action('Aceitar convite', $this->acceptUrl)
            ->line('O convite expira em '.$this->expiresAt->setTimezone((string) config('app.business_timezone'))->format('d/m/Y H:i').'.')
            ->line('Se você não esperava este convite, ignore esta mensagem.');
    }
}
