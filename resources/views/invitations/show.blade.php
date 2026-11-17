<x-layouts.guest title="Convite">
    <h1 class="text-2xl font-semibold text-slate-900">Convite para o Fornevia</h1>
    @if ($user)
        <p class="mt-2 text-sm text-slate-600">Você está conectado como <strong>{{ $user->email }}</strong>. O convite só pode ser aceito pela conta do e-mail convidado.</p>
        <form method="POST" action="{{ route('invitations.accept', ['organization' => $organization, 'token' => $token]) }}" class="mt-6">
            @csrf
            <x-ui.button type="submit" class="w-full">Aceitar convite</x-ui.button>
        </form>
    @else
        <p class="mt-2 text-sm text-slate-600">Entre ou crie uma conta com o e-mail que recebeu o convite e volte a este link.</p>
        <div class="mt-6 flex gap-3">
            <a href="{{ route('login') }}" class="fv-btn-primary">Entrar</a>
            <a href="{{ route('register') }}" class="fv-btn-secondary">Criar conta</a>
        </div>
    @endif
</x-layouts.guest>
