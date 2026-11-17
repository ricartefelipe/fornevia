<x-layouts.guest title="Criar conta">
    <h1 class="text-2xl font-semibold text-slate-900">Criar conta</h1>
    <p class="mt-1 text-sm text-slate-600">Depois de confirmar o e-mail, você cadastra a sua distribuidora ou aceita um convite recebido.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-ui.input label="Nome completo" name="name" :value="old('name')" autocomplete="name" required autofocus />
        <x-ui.input label="E-mail corporativo" name="email" type="email" :value="old('email')" autocomplete="email" required />
        <x-ui.input label="Senha" name="password" type="password" autocomplete="new-password" required hint="Mínimo de 12 caracteres, com letras e números." />
        <x-ui.input label="Confirmar senha" name="password_confirmation" type="password" autocomplete="new-password" required />
        <div>
            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" name="terms" value="1" class="mt-0.5 rounded border-slate-300 text-brand-700 focus:ring-brand-600" @checked(old('terms'))>
                Li e aceito os termos de uso e a política de privacidade.
            </label>
            @error('terms')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        <x-ui.button type="submit" class="w-full">Criar conta</x-ui.button>
    </form>

    <p class="mt-6 text-sm text-slate-600">Já possui conta? <a href="{{ route('login') }}" class="font-medium text-brand-700 hover:underline">Entrar</a></p>
</x-layouts.guest>
