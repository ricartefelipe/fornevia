{{-- Situação de um dos estados do pedido (comercial, aprovação, atendimento, financeiro). --}}
@php($color = match ($status) {
    \App\Enums\Ordering\CommercialStatus::Confirmed, \App\Enums\Ordering\CommercialStatus::Completed,
    \App\Enums\Ordering\ApprovalStatus::Approved, \App\Enums\Ordering\FulfillmentStatus::Delivered,
    \App\Enums\Ordering\FinancialStatus::Paid => 'green',
    \App\Enums\Ordering\CommercialStatus::PendingApproval, \App\Enums\Ordering\CommercialStatus::PartiallyCancelled,
    \App\Enums\Ordering\ApprovalStatus::Pending, \App\Enums\Ordering\FulfillmentStatus::PartiallyShipped,
    \App\Enums\Ordering\FulfillmentStatus::PartiallyDelivered, \App\Enums\Ordering\FinancialStatus::PartiallyPaid => 'amber',
    \App\Enums\Ordering\CommercialStatus::Cancelled, \App\Enums\Ordering\CommercialStatus::Rejected,
    \App\Enums\Ordering\ApprovalStatus::Rejected => 'red',
    \App\Enums\Ordering\CommercialStatus::Approved, \App\Enums\Ordering\FulfillmentStatus::Shipped,
    \App\Enums\Ordering\FinancialStatus::PartiallyInvoiced, \App\Enums\Ordering\FinancialStatus::Invoiced => 'blue',
    default => 'slate',
})
<x-ui.badge :color="$color">{{ $status->label() }}</x-ui.badge>
