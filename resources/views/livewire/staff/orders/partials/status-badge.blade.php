{{-- Selo de um dos estados independentes do pedido (ADR-009). --}}
@php
    $color = match ($status->value) {
        'confirmed', 'approved', 'completed', 'delivered', 'paid' => 'green',
        'pending_approval', 'pending', 'partially_cancelled', 'partially_shipped', 'partially_delivered', 'partially_invoiced', 'partially_paid' => 'amber',
        'cancelled', 'rejected' => 'red',
        'shipped', 'invoiced' => 'blue',
        default => 'slate',
    };
@endphp
<x-ui.badge :color="$color">{{ $status->label() }}</x-ui.badge>
