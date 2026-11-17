<x-layouts.guest title="Recuperar senha">
    <h1 class="text-2xl font-semibold text-slate-900">Recuperar senha</h1>
    <p class="mt-1 text-sm text-slate-600">Informe o e-mail da conta. Se ele estiver cadastrado, enviaremos um link de redefinição.</p>
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-ui.input label="E-mail" name="email" type="email" :value="old('email')" autocomplete="email" required autofocus />
        <x-ui.button type="submit" class="w-full">Enviar link</x-ui.button>
    </form>
    <p class="mt-6 text-sm"><a href="{{ route('login') }}" class="font-medium text-brand-700 hover:underline">Voltar ao login</a></p>
</x-layouts.guest>
