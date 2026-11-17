@props(['title', 'description' => null])
<div {{ $attributes->class(['rounded-lg border border-dashed border-slate-300 bg-white px-6 py-10 text-center']) }}>
    <p class="text-sm font-semibold text-slate-800">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 text-sm text-slate-600">{{ $description }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
