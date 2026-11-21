{{-- Navegação entre as telas de preços. A rota atual é informada pela tela,
     pois nas ações do Livewire a requisição corrente é a de atualização. --}}
@php($sections = [
    'pricing.lists' => 'Tabelas de preço',
    'pricing.negotiated' => 'Condições negociadas',
    'pricing.payment-terms' => 'Condições de pagamento',
    'pricing.simulator' => 'Simulador',
])
<nav aria-label="Seções de preços" class="mb-6 border-b border-slate-200">
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
