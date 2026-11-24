<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}Fornevia</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    <a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:rounded focus:bg-white focus:px-3 focus:py-2">Ir para o conteúdo</a>
    <div class="flex min-h-full">
        <aside class="fv-hero relative hidden w-[44%] flex-col justify-between overflow-hidden p-12 text-brand-50 lg:flex">
            @include('layouts.partials.brand', ['subtitle' => 'Vendas B2B para distribuidores e atacadistas'])
            <div class="relative">
                <p class="max-w-md text-3xl font-semibold leading-tight tracking-tight text-white">Toda a venda B2B do distribuidor em um só lugar.</p>
                <ul class="mt-8 space-y-4 text-sm text-brand-100">
                    <li class="flex gap-3"><x-ui.icon name="tag" class="size-5 shrink-0 text-brand-300" /> Catálogo e preços próprios para cada cliente empresarial.</li>
                    <li class="flex gap-3"><x-ui.icon name="badge-check" class="size-5 shrink-0 text-brand-300" /> Cotações negociadas, aprovações por alçada e pedidos com reserva de estoque.</li>
                    <li class="flex gap-3"><x-ui.icon name="card" class="size-5 shrink-0 text-brand-300" /> Crédito comercial, expedição, devoluções e recebimentos no mesmo fluxo.</li>
                </ul>
            </div>
            <p class="relative text-xs text-brand-200/70">Cada distribuidor com seus dados isolados e protegidos.</p>
        </aside>
        <main id="conteudo" class="flex flex-1 items-center justify-center bg-linear-to-b from-white to-brand-50/60 px-6 py-12">
            <div class="w-full max-w-md">
                <div class="mb-8 lg:hidden">
                    <span class="inline-flex items-center gap-2 text-xl font-semibold text-brand-800">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-brand-500 to-accent-500 text-base font-bold text-white" aria-hidden="true">F</span> Fornevia
                    </span>
                </div>
                <x-ui.flash />
                <div class="mt-4 rounded-3xl border border-slate-200/80 bg-white p-8 shadow-xl shadow-brand-900/5">{{ $slot }}</div>
            </div>
        </main>
    </div>
</body>
</html>
