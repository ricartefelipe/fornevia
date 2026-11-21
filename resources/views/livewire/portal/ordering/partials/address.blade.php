{{-- Endereço gravado no pedido (snapshot). --}}
@php($address = is_array($address ?? null) ? $address : [])
@if ($address === [])
    <p class="text-sm text-slate-600">Não informado.</p>
@else
    <address class="text-sm not-italic text-slate-700">
        @if (! empty($address['label']))
            <span class="font-medium text-slate-900">{{ $address['label'] }}</span><br>
        @endif
        @if (! empty($address['recipient']))
            A/C {{ $address['recipient'] }}<br>
        @endif
        {{ $address['street'] ?? '' }}, {{ $address['number'] ?? '' }}@if (! empty($address['complement'])) - {{ $address['complement'] }}@endif<br>
        {{ $address['district'] ?? '' }} · {{ $address['city'] ?? '' }}/{{ $address['state'] ?? '' }}<br>
        CEP {{ \App\Domain\Buyers\PostalCode::format((string) ($address['postal_code'] ?? '')) }}
    </address>
@endif
