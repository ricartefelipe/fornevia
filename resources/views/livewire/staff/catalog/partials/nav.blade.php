{{-- Navegação entre as telas do catálogo da equipe. --}}
<nav aria-label="Seções do catálogo" class="mb-6 border-b border-slate-200">
    <ul class="-mb-px flex flex-wrap gap-4 text-sm font-medium">
        @foreach ([
            'catalog.products' => 'Produtos',
            'catalog.categories' => 'Categorias',
            'catalog.units' => 'Unidades de medida',
            'catalog.private' => 'Catálogos privados',
        ] as $routeName => $label)
            @php($current = request()->routeIs($routeName) || ($routeName === 'catalog.products' && request()->routeIs('catalog.products.*')))
            <li>
                <a href="{{ route($routeName) }}"
                    @class([
                        'inline-block border-b-2 px-1 pb-2',
                        'border-brand-700 text-brand-800' => $current,
                        'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-800' => ! $current,
                    ])
                    @if ($current) aria-current="page" @endif>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
