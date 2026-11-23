<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Actions\Notifications\MyNotifications;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Atalho para a central de notificações no cabeçalho, com o total não lido
 * atualizado periodicamente.
 */
final class NotificationBell extends Component
{
    public function render(MyNotifications $notifications, Access $access): View
    {
        return view('livewire.notifications.notification-bell', [
            'unread' => $notifications->unreadCount(),
            'url' => route($access->isBuyer() ? 'portal.notifications' : 'notifications.index'),
        ]);
    }
}
