@props(['color' => 'slate'])
@php($styles = [
    'slate' => 'bg-slate-100 text-slate-700',
    'green' => 'bg-emerald-100 text-emerald-800',
    'amber' => 'bg-amber-100 text-amber-800',
    'red' => 'bg-red-100 text-red-800',
    'blue' => 'bg-sky-100 text-sky-800',
    'brand' => 'bg-brand-100 text-brand-800',
][$color] ?? 'bg-slate-100 text-slate-700')
<span {{ $attributes->class(['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium', $styles]) }}>{{ $slot }}</span>
