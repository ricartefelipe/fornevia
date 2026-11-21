{{-- Navegação entre as telas de estoque. A rota atual é informada pela tela,
     pois nas ações do Livewire a requisição corrente é a de atualização. --}}
@php($sections = array_filter([
    'inventory.index' => 'Saldos',
    'inventory.movements' => 'Movimentações',
    'inventory.operations' => ($canManage ?? false) ? 'Entradas, ajustes e transferências' : null,
    'inventory.warehouses' => 'Depósitos',
]))
<nav aria-label="Seções do estoque" class="mb-6 border-b border-slate-200">
    <ul class="-mb-px flex flex-wrap gap-x-6 gap-y-2 text-sm">
        @foreach ($sections as $route => $label)
            <li>
                <a href="{{ route($route) }}"
                    @class([
                        'inline-block border-b-2 px-1 pb-2 font-medium',
                        'border-brand-700 text-brand-800' => $current === $route,
                        'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => $current !== $route,
                    ])
                    @if ($current === $route) aria-current="page" @endif>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
