<nav class="mb-4 flex flex-wrap gap-2" aria-label="Seções de integrações">
    @foreach (['integrations.index' => 'API', 'integrations.webhooks' => 'Webhooks', 'integrations.imports' => 'Importação CSV'] as $tabRoute => $tabLabel)
        <a href="{{ route($tabRoute) }}" @class(['fv-btn-secondary', 'ring-2 ring-brand-500' => request()->routeIs($tabRoute)]) @if (request()->routeIs($tabRoute)) aria-current="page" @endif>{{ $tabLabel }}</a>
    @endforeach
</nav>
