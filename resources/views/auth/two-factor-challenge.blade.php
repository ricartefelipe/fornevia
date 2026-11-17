<x-layouts.guest title="Verificação em duas etapas">
    <h1 class="text-2xl font-semibold text-slate-900">Verificação em duas etapas</h1>
    <p class="mt-1 text-sm text-slate-600">Informe o código do aplicativo autenticador ou um código de recuperação.</p>
    <form method="POST" action="{{ route('two-factor.login') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-ui.input label="Código do autenticador" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus />
        <x-ui.input label="Ou código de recuperação" name="recovery_code" autocomplete="off" />
        <x-ui.button type="submit" class="w-full">Confirmar</x-ui.button>
    </form>
</x-layouts.guest>
