@php($subtitle ??= null)
<span class="flex items-center gap-3">
    <span class="flex size-9 items-center justify-center rounded-xl bg-linear-to-br from-brand-400 to-accent-500 text-lg font-bold text-white shadow-lg shadow-brand-900/40" aria-hidden="true">F</span>
    <span class="leading-tight">
        <span class="block text-lg font-semibold tracking-tight text-white">Fornevia</span>
        @if ($subtitle)<span class="block text-xs text-brand-200/80">{{ $subtitle }}</span>@endif
    </span>
</span>
