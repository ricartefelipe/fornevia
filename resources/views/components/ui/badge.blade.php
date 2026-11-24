@props(['color' => 'slate'])
@php($styles = [
    'slate' => 'bg-slate-50 text-slate-700 ring-slate-200',
    'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
    'red' => 'bg-red-50 text-red-700 ring-red-200',
    'blue' => 'bg-sky-50 text-sky-700 ring-sky-200',
    'brand' => 'bg-brand-50 text-brand-700 ring-brand-200',
][$color] ?? 'bg-slate-50 text-slate-700 ring-slate-200')
<span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', $styles]) }}>{{ $slot }}</span>
