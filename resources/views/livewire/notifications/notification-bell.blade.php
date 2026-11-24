<a href="{{ $url }}" wire:poll.60s class="relative inline-flex size-9 items-center justify-center rounded-full text-slate-600 transition hover:bg-brand-50 hover:text-brand-700" title="Notificações">
    <x-ui.icon name="bell" class="size-5" />
    <span class="sr-only">Notificações</span>
    @if ($unread > 0)
        <span class="absolute -top-0.5 -right-0.5 min-w-5 rounded-full bg-linear-to-br from-brand-500 to-accent-500 px-1.5 py-0.5 text-center text-[10px] leading-none font-semibold text-white ring-2 ring-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        <span class="sr-only">{{ $unread === 1 ? 'não lida' : 'não lidas' }}</span>
    @endif
</a>
