@inject('access', 'App\Support\Auth\Access')
@inject('menu', 'App\Support\Navigation\Menu')
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
    <a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">Ir para o conteúdo</a>
    @php($membership = $access->membershipOrNull())
    @php($items = $menu->items())
    <div class="min-h-full lg:flex">
        <nav aria-label="Navegação principal" class="bg-brand-900 lg:w-64 lg:shrink-0">
            <details class="group lg:hidden">
                <summary class="flex cursor-pointer items-center justify-between px-4 py-3 text-white">
                    <span class="font-bold">Fornevia</span>
                    <span class="text-sm text-brand-200">Menu</span>
                </summary>
                @include('layouts.partials.nav-items', ['items' => $items])
            </details>
            <div class="hidden lg:block">
                <p class="px-6 pt-6 text-xl font-bold tracking-tight text-white">Fornevia</p>
                @if ($membership)
                    <p class="px-6 text-xs text-brand-300">{{ $access->isBuyer() ? 'Portal do comprador' : 'Equipe do distribuidor' }}</p>
                @endif
                @include('layouts.partials.nav-items', ['items' => $items])
            </div>
        </nav>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-6 py-3">
                <div class="text-sm text-slate-700">
                    @if ($membership)
                        <span class="font-semibold text-slate-900">{{ $membership->tenant->trade_name }}</span>
                        @if ($access->isBuyer())
                            <span class="text-slate-500">· {{ $membership->buyerCompany?->trade_name }}</span>
                        @endif
                        <span class="text-slate-500">· {{ $membership->role->name }}</span>
                    @else
                        <span class="text-slate-500">Nenhum contexto selecionado</span>
                    @endif
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <a href="{{ route('contexts.index', ['escolher' => 1]) }}" class="font-medium text-brand-700 hover:underline">Trocar contexto</a>
                    <a href="{{ route('profile.security') }}" class="text-slate-700 hover:underline">{{ auth()->user()?->name }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-slate-600 hover:underline">Sair</button>
                    </form>
                </div>
            </header>

            <main id="conteudo" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <x-ui.flash />
                <div class="mt-2">{{ $slot }}</div>
            </main>
        </div>
    </div>
</body>
</html>
