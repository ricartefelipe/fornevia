<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de uma notificação do sistema. Enviado depois do commit do
 * consumo que criou a notificação; o conteúdo já vem pronto, sem consulta
 * ao banco no envio.
 */
final class ActivityMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $organizationName,
        public readonly string $title,
        public readonly string $body,
        public readonly string $actionLabel,
        public readonly string $url,
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
            ->subject("{$this->title} · {$this->organizationName}")
            ->greeting('Olá!')
            ->line($this->body)
            ->action($this->actionLabel, $this->url)
            ->line('Você pode desligar estes e-mails na sua central de notificações.');
    }
}
