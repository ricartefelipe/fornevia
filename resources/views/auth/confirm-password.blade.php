<x-layouts.guest title="Confirmar senha">
    <h1 class="text-2xl font-semibold text-slate-900">Confirme sua senha</h1>
    <p class="mt-1 text-sm text-slate-600">Esta é uma área protegida. Confirme sua senha para continuar.</p>
    <form method="POST" action="{{ route('password.confirm') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-ui.input label="Senha" name="password" type="password" autocomplete="current-password" required autofocus />
        <x-ui.button type="submit" class="w-full">Confirmar</x-ui.button>
    </form>
</x-layouts.guest>
