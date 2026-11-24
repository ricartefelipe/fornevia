<div>
    <x-ui.page-header title="Notificações" description="Avisos sobre cotações, aprovações e pedidos que pedem a sua atenção.">
        <x-slot:actions>
            @if ($unread > 0)
                <x-ui.button variant="secondary" wire:click="markAllRead" wire:loading.attr="disabled">Marcar todas como lidas</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4">
        @if ($message)
            <x-ui.alert type="success">{{ $message }}</x-ui.alert>
        @endif
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" wire:model.live="onlyUnread" class="rounded border-slate-300"> Só não lidas ({{ $unread }})
        </label>
        <div class="flex items-center gap-3 text-sm text-slate-700">
            <span>E-mail: {{ $emailEnabled ? 'ligado' : 'desligado' }}</span>
            <x-ui.button variant="secondary" wire:click="toggleEmail" wire:loading.attr="disabled">{{ $emailEnabled ? 'Desligar e-mails' : 'Ligar e-mails' }}</x-ui.button>
        </div>
    </div>

    <ol class="fv-card divide-y divide-slate-200">
        @forelse ($notifications as $notification)
            <li wire:key="notificacao-{{ $notification->id }}" class="flex flex-wrap items-start justify-between gap-3 p-4 {{ $notification->read_at ? '' : 'bg-brand-50' }}">
                <div class="min-w-0">
                    <p class="font-medium text-slate-900">
                        @unless ($notification->read_at) <span class="sr-only">Não lida:</span> @endunless
                        {{ $notification->title }}
                    </p>
                    <p class="text-sm text-slate-700">{{ $notification->body }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $notification->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</p>
                </div>
                <x-ui.button variant="secondary" wire:click="open('{{ $notification->id }}')" wire:loading.attr="disabled">Abrir</x-ui.button>
            </li>
        @empty
            <li class="p-4 text-sm text-slate-600">{{ $onlyUnread ? 'Nenhuma notificação não lida.' : 'Nenhuma notificação ainda.' }}</li>
        @endforelse
    </ol>

    <div class="mt-4">{{ $notifications->links() }}</div>
</div>
