<x-layouts.guest title="Confirmar e-mail">
    <h1 class="text-2xl font-semibold text-slate-900">Confirme seu e-mail</h1>
    <p class="mt-2 text-sm text-slate-600">Enviamos um link de confirmação para o seu e-mail. Confirme para continuar.</p>
    @if (session('status') === 'verification-link-sent')
        <x-ui.alert type="success" class="mt-4">Um novo link foi enviado.</x-ui.alert>
    @endif
    <div class="mt-6 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit">Reenviar link</x-ui.button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm text-slate-600 hover:underline">Sair</button>
        </form>
    </div>
</x-layouts.guest>
