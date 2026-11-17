@props(['variant' => 'primary', 'type' => 'button'])
<button type="{{ $type }}" {{ $attributes->class(['fv-btn-'.$variant]) }}>
    {{ $slot }}
</button>
