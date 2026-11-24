@props(['title', 'description' => null])
<div {{ $attributes->class(['rounded-2xl border border-dashed border-brand-200 bg-white px-6 py-12 text-center']) }}>
    <span class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-brand-100" aria-hidden="true">
        <x-ui.icon name="squares" class="size-6" />
    </span>
    <p class="mt-4 text-sm font-semibold text-slate-900">{{ $title }}</p>
    @if ($description)
        <p class="mx-auto mt-1 max-w-md text-sm text-slate-600">{{ $description }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
