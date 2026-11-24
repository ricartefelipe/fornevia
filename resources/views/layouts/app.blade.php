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
    @php($user = auth()->user())
    @php($initials = collect(preg_split('/\s+/u', trim((string) $user?->name)) ?: [])->filter(fn ($p) => preg_match('/^\p{L}/u', $p) === 1)->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''))
    <div class="min-h-full lg:flex">
        <nav aria-label="Navegação principal" class="fv-sidebar lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-72 lg:shrink-0 lg:flex-col">
            <details class="group lg:hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-white">
                    @include('layouts.partials.brand', ['subtitle' => null])
                    <span class="flex items-center gap-2 rounded-lg bg-white/10 px-3 py-1.5 text-sm text-brand-100"><x-ui.icon name="bars" class="size-5" /> Menu</span>
                </summary>
                @include('layouts.partials.nav-items', ['items' => $items])
            </details>
            <div class="hidden min-h-0 flex-1 flex-col lg:flex">
                <div class="px-6 pt-6 pb-2">
                    @include('layouts.partials.brand', ['subtitle' => $membership ? ($access->isBuyer() ? 'Portal do comprador' : 'Equipe do distribuidor') : null])
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    @include('layouts.partials.nav-items', ['items' => $items])
                </div>
                @if ($membership)
                    <div class="m-3 rounded-2xl bg-white/5 p-3 ring-1 ring-white/10">
                        <p class="truncate text-sm font-semibold text-white">{{ $membership->tenant->trade_name }}</p>
                        <p class="truncate text-xs text-brand-200/80">{{ $access->isBuyer() ? $membership->buyerCompany?->trade_name.' · ' : '' }}{{ $membership->role->name }}</p>
                        <a href="{{ route('contexts.index', ['escolher' => 1]) }}" class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-brand-200 hover:text-white">
                            <x-ui.icon name="switch" class="size-4" /> Trocar contexto
                        </a>
                    </div>
                @endif
            </div>
        </nav>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 bg-white/80 px-4 py-3 backdrop-blur sm:px-6 lg:px-8">
                <div class="min-w-0 text-sm text-slate-600">
                    @if ($membership)
                        <span class="font-semibold text-slate-900">{{ $membership->tenant->trade_name }}</span>
                        @if ($access->isBuyer())
                            <span class="text-slate-500">· {{ $membership->buyerCompany?->trade_name }}</span>
                        @endif
                        <span class="ml-2 inline-flex items-center rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700 ring-1 ring-brand-200">{{ $membership->role->name }}</span>
                    @else
                        <span class="text-slate-500">Nenhum contexto selecionado</span>
                    @endif
                </div>
                <div class="flex items-center gap-2 text-sm">
                    @if ($membership)
                        <livewire:notifications.notification-bell />
                    @endif
                    <a href="{{ route('contexts.index', ['escolher' => 1]) }}" class="rounded-lg px-2.5 py-1.5 font-medium text-brand-700 hover:bg-brand-50 lg:hidden">Trocar contexto</a>
                    <a href="{{ route('profile.security') }}" class="flex items-center gap-2 rounded-full py-1 pr-3 pl-1 text-slate-700 hover:bg-slate-100">
                        <span class="flex size-8 items-center justify-center rounded-full bg-linear-to-br from-brand-500 to-accent-500 text-xs font-semibold text-white" aria-hidden="true">{{ $initials }}</span>
                        <span class="hidden sm:inline">{{ $user?->name }}</span>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                            <x-ui.icon name="logout" class="size-4" /> Sair
                        </button>
                    </form>
                </div>
            </header>

            <main id="conteudo" class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <div class="mx-auto max-w-7xl">
                    <x-ui.flash />
                    <div class="mt-2">{{ $slot }}</div>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
