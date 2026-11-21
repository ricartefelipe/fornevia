<x-layouts.guest title="Escolher contexto">
    <h1 class="text-2xl font-semibold text-slate-900">Onde você vai trabalhar agora?</h1>
    <p class="mt-1 text-sm text-slate-600">Cada contexto tem permissões próprias. Dados de um distribuidor nunca aparecem em outro.</p>

    @if ($options === [])
        <x-ui.empty-state class="mt-6" title="Você ainda não participa de nenhuma organização"
            description="Cadastre sua distribuidora para iniciar o período de avaliação ou aceite o convite enviado ao seu e-mail.">
            <a href="{{ route('onboarding') }}" class="fv-btn-primary">Cadastrar distribuidora</a>
        </x-ui.empty-state>
    @else
        <ul class="mt-6 space-y-3" role="list">
            @foreach ($options as $option)
                <li>
                    <form method="POST" action="{{ route('contexts.store') }}">
                        @csrf
                        <input type="hidden" name="membership_id" value="{{ $option->membershipId }}">
                        <button type="submit" @disabled(! $option->available)
                            class="fv-card flex w-full items-center justify-between px-4 py-3 text-left hover:border-brand-400 disabled:opacity-60">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ $option->label() }}</span>
                                <span class="block text-xs text-slate-600">{{ $option->roleName }}</span>
                            </span>
                            @if ($option->available)
                                <span class="text-sm font-medium text-brand-700">Entrar</span>
                            @else
                                <x-ui.badge color="red">Indisponível</x-ui.badge>
                            @endif
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
        <p class="mt-6 text-sm text-slate-600">
            É um novo distribuidor? <a href="{{ route('onboarding') }}" class="font-medium text-brand-700 hover:underline">Cadastrar outra organização</a>
        </p>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="mt-8">
        @csrf
        <button type="submit" class="text-sm text-slate-600 hover:underline">Sair</button>
    </form>
</x-layouts.guest>
