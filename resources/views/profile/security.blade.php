<x-layouts.app title="Segurança da conta">
    @php($user = auth()->user())
    <x-ui.page-header title="Segurança da conta" description="Dados de acesso, senha e autenticação em dois fatores." />

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="fv-card p-6" aria-labelledby="sec-2fa">
            <h2 id="sec-2fa" class="text-lg font-semibold">Autenticação em dois fatores</h2>
            <p class="mt-1 text-sm text-slate-600">Obrigatória para perfis com gestão de equipe, crédito, financeiro, integrações ou organização.</p>

            @if (session('status') === 'two-factor-authentication-confirmed')
                <x-ui.alert type="success" class="mt-4">Autenticação em dois fatores ativada.</x-ui.alert>
            @endif

            @if (! $user->two_factor_secret)
                <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-4">
                    @csrf
                    <x-ui.button type="submit">Ativar</x-ui.button>
                </form>
            @elseif (! $user->two_factor_confirmed_at)
                <p class="mt-4 text-sm text-slate-700">Leia o QR Code no aplicativo autenticador e informe o código gerado.</p>
                {{-- SVG gerado pelo Fortify a partir do segredo do próprio usuário (conteúdo confiável). --}}
                <div class="mt-3 inline-block rounded border border-slate-200 bg-white p-3" role="img" aria-label="QR Code para o aplicativo autenticador">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="mt-4 space-y-3">
                    @csrf
                    <x-ui.input label="Código do autenticador" name="code" inputmode="numeric" autocomplete="one-time-code" required />
                    @error('code', 'confirmTwoFactorAuthentication')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                    <x-ui.button type="submit">Confirmar</x-ui.button>
                </form>
            @else
                <x-ui.badge color="green" class="mt-4">Ativa</x-ui.badge>
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-brand-700">Códigos de recuperação</summary>
                    <ul class="mt-2 grid grid-cols-2 gap-1 font-mono text-xs">
                        @foreach ($user->recoveryCodes() as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>
                    <form method="POST" action="{{ url('/user/two-factor-recovery-codes') }}" class="mt-3">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">Gerar novos códigos</x-ui.button>
                    </form>
                </details>
                <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-4">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">Desativar</x-ui.button>
                </form>
            @endif
        </section>

        <section class="fv-card p-6" aria-labelledby="sec-password">
            <h2 id="sec-password" class="text-lg font-semibold">Senha</h2>
            @if (session('status') === 'password-updated')
                <x-ui.alert type="success" class="mt-4">Senha atualizada.</x-ui.alert>
            @endif
            <form method="POST" action="{{ route('user-password.update') }}" class="mt-4 space-y-3">
                @csrf
                @method('PUT')
                <x-ui.input label="Senha atual" name="current_password" type="password" autocomplete="current-password" required />
                @error('current_password', 'updatePassword')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                <x-ui.input label="Nova senha" name="password" type="password" autocomplete="new-password" required />
                @error('password', 'updatePassword')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                <x-ui.input label="Confirmar nova senha" name="password_confirmation" type="password" autocomplete="new-password" required />
                <x-ui.button type="submit">Alterar senha</x-ui.button>
            </form>
        </section>

        <section class="fv-card p-6 lg:col-span-2" aria-labelledby="sec-profile">
            <h2 id="sec-profile" class="text-lg font-semibold">Dados pessoais</h2>
            @if (session('status') === 'profile-information-updated')
                <x-ui.alert type="success" class="mt-4">Dados atualizados.</x-ui.alert>
            @endif
            <form method="POST" action="{{ route('user-profile-information.update') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                @csrf
                @method('PUT')
                <x-ui.input label="Nome" name="name" :value="old('name', $user->name)" required />
                <x-ui.input label="E-mail" name="email" type="email" :value="old('email', $user->email)" required />
                <div class="sm:col-span-2"><x-ui.button type="submit">Salvar</x-ui.button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
