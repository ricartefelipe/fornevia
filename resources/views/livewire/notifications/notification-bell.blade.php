<a href="{{ $url }}" wire:poll.60s class="relative inline-flex items-center gap-1 font-medium text-slate-700 hover:underline">
    Notificações
    @if ($unread > 0)
        <span class="rounded-full bg-brand-700 px-2 py-0.5 text-xs font-semibold text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        <span class="sr-only">{{ $unread === 1 ? 'não lida' : 'não lidas' }}</span>
    @endif
</a>
