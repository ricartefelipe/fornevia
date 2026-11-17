{{-- Mensagens de sessão. Códigos de status do Fortify (ex.: "password-updated")
     são tratados pelas próprias telas e não aparecem aqui como texto. --}}
<div aria-live="polite" class="space-y-2">
    @foreach (['status' => 'success', 'success' => 'success', 'warning' => 'warning', 'error' => 'error'] as $key => $type)
        @php($flash = session($key))
        @if (is_string($flash) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/', $flash) !== 1)
            <x-ui.alert :type="$type">{{ $flash }}</x-ui.alert>
        @endif
    @endforeach
</div>
