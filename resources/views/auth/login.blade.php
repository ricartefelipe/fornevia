<x-layouts.guest title="Entrar">
    <h1 class="text-2xl font-semibold text-slate-900">Entrar</h1>
    <p class="mt-1 text-sm text-slate-600">Acesse com o e-mail cadastrado.</p>

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-ui.input label="E-mail" name="email" type="email" :value="old('email')" autocomplete="username" required autofocus />
        <x-ui.input label="Senha" name="password" type="password" autocomplete="current-password" required />
        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                Manter conectado
            </label>
            <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-700 hover:underline">Esqueci a senha</a>
        </div>
        <x-ui.button type="submit" class="w-full">Entrar</x-ui.button>
    </form>

    <p class="mt-6 text-sm text-slate-600">
        Distribuidor ainda sem conta?
        <a href="{{ route('register') }}" class="font-medium text-brand-700 hover:underline">Comece o período de avaliação</a>
    </p>
</x-layouts.guest>
