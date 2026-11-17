<x-layouts.guest title="Redefinir senha">
    <h1 class="text-2xl font-semibold text-slate-900">Redefinir senha</h1>
    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-ui.input label="E-mail" name="email" type="email" :value="old('email', $request->email)" autocomplete="username" required />
        <x-ui.input label="Nova senha" name="password" type="password" autocomplete="new-password" required hint="Mínimo de 12 caracteres, com letras e números." />
        <x-ui.input label="Confirmar nova senha" name="password_confirmation" type="password" autocomplete="new-password" required />
        <x-ui.button type="submit" class="w-full">Redefinir senha</x-ui.button>
    </form>
</x-layouts.guest>
