{{-- Paginação dos pedidos do portal sem expressões Alpine (compatível com a CSP estrita). --}}
<div>
    @if ($paginator->hasPages())
        <nav aria-label="Paginação dos pedidos" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-600">
                Exibindo {{ $paginator->firstItem() }} a {{ $paginator->lastItem() }} de {{ $paginator->total() }} pedidos
            </p>
            <ul class="flex flex-wrap items-center gap-1">
                <li>
                    @if ($paginator->onFirstPage())
                        <span class="fv-btn-secondary cursor-not-allowed opacity-60" aria-disabled="true">Anterior</span>
                    @else
                        <button type="button" class="fv-btn-secondary" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">Anterior</button>
                    @endif
                </li>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li><span class="px-2 text-sm text-slate-500" aria-hidden="true">{{ $element }}</span></li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $number => $url)
                            <li wire:key="pagina-{{ $number }}">
                                @if ($number == $paginator->currentPage())
                                    <span class="fv-btn-primary" aria-current="page">
                                        <span class="sr-only">Página </span>{{ $number }}
                                    </span>
                                @else
                                    <button type="button" class="fv-btn-secondary" wire:click="gotoPage({{ (int) $number }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">
                                        <span class="sr-only">Página </span>{{ $number }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    @endif
                @endforeach

                <li>
                    @if ($paginator->hasMorePages())
                        <button type="button" class="fv-btn-secondary" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">Próxima</button>
                    @else
                        <span class="fv-btn-secondary cursor-not-allowed opacity-60" aria-disabled="true">Próxima</span>
                    @endif
                </li>
            </ul>
        </nav>
    @endif
</div>
