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
        <aside class="hidden w-2/5 flex-col justify-between bg-brand-900 p-12 text-brand-50 lg:flex">
            <div>
                <p class="text-2xl font-bold tracking-tight">Fornevia</p>
                <p class="mt-2 text-sm text-brand-200">Vendas B2B para distribuidores e atacadistas</p>
            </div>
            <ul class="space-y-3 text-sm text-brand-100">
                <li>Catálogo e preços próprios para cada cliente empresarial.</li>
                <li>Cotações negociadas, aprovações por alçada e pedidos com reserva de estoque.</li>
                <li>Crédito comercial, expedição, devoluções e recebimentos no mesmo lugar.</li>
            </ul>
        </aside>
        <main id="conteudo" class="flex flex-1 items-center justify-center px-6 py-12">
            <div class="w-full max-w-md">
                <p class="mb-8 text-xl font-bold text-brand-800 lg:hidden">Fornevia</p>
                <x-ui.flash />
                <div class="mt-4">{{ $slot }}</div>
            </div>
        </main>
    </div>
</body>
</html>
