<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Actions\Notifications\MyNotifications;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Central de notificações da pessoa no contexto atual (equipe ou portal):
 * lista, abrir (marca como lida e leva à tela do assunto), marcar todas e
 * ligar ou desligar o e-mail.
 */
#[Layout('layouts.app')]
#[Title('Notificações')]
final class NotificationsPage extends Component
{
    use WithPagination;

    #[Url(as: 'nao-lidas', except: false)]
    public bool $onlyUnread = false;

    public string $message = '';

    public function updatedOnlyUnread(): void
    {
        $this->resetPage();
    }

    public function open(string $id, MyNotifications $notifications): mixed
    {
        try {
            return $this->redirect($notifications->open($id));
        } catch (ModelNotFoundException) {
            $this->message = 'Notificação não encontrada.';

            return null;
        }
    }

    public function markAllRead(MyNotifications $notifications): void
    {
        $count = $notifications->markAllRead();
        $this->message = $count === 1 ? '1 notificação marcada como lida.' : "{$count} notificações marcadas como lidas.";
    }

    public function toggleEmail(MyNotifications $notifications): void
    {
        $enabled = ! $notifications->emailEnabled();
        $notifications->setEmailEnabled($enabled);
        $this->message = $enabled ? 'Você voltará a receber estas notificações por e-mail.' : 'Você não receberá mais estas notificações por e-mail neste contexto.';
    }

    public function render(MyNotifications $notifications): View
    {
        return view('livewire.notifications.notifications-page', [
            'notifications' => $notifications->paginate($this->onlyUnread, $this->getPage()),
            'unread' => $notifications->unreadCount(),
            'emailEnabled' => $notifications->emailEnabled(),
            'timezone' => (string) config('app.business_timezone'),
        ]);
    }
}
