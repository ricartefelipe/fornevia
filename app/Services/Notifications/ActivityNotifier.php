<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Shared\Money;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteCommercialReview;
use App\Models\Quotes\QuoteVersion;
use App\Support\Outbox\OutboxConsumer;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Collection;

/**
 * Notificações das atividades de cotação, aprovação interna e pedido
 * (ADR-026). Cada tipo de mensagem define o texto, a tela que resolve o
 * assunto e quem precisa saber; mensagem cujo assunto já não existe é
 * ignorada.
 */
final readonly class ActivityNotifier implements OutboxConsumer
{
    public function __construct(
        private NotificationRecipients $recipients,
        private NotificationCenter $center,
    ) {}

    public function name(): string
    {
        return 'notifications';
    }

    public function queue(): string
    {
        return 'notifications';
    }

    public function types(): array
    {
        return [
            'quote.requested', 'quote.counteroffer_submitted', 'quote.commercial_approval_requested',
            'quote.commercial_approval_decided', 'quote.proposal_sent', 'quote.proposal_withdrawn', 'quote.expired',
            'quote.stock_hold_expired', 'quote.accepted', 'quote.rejected', 'quote.cancelled', 'quote.comment_added',
            'order.submitted_for_approval', 'order.approval_level_approved', 'order.approvals_invalidated',
            'order.approval_step_reassigned', 'order.approval_rejected', 'order.confirmation_pending',
            'order.confirmed', 'order.cancelled',
        ];
    }

    public function handle(OutboxMessage $message): void
    {
        $delivery = str_starts_with($message->type, 'quote.') ? $this->quote($message) : $this->order($message);

        if ($delivery !== null) {
            $this->center->deliver($message, $delivery[0], $delivery[1]);
        }
    }

    /**
     * @return array{NotificationContent, Collection<int, Membership>}|null
     */
    private function quote(OutboxMessage $message): ?array
    {
        $quote = Quote::query()->with('buyerCompany')->find($message->string('quote_id'));

        if ($quote === null) {
            return null;
        }

        $number = $quote->number;
        $buyer = $quote->buyerCompany;
        $company = $buyer !== null ? $buyer->trade_name : 'O comprador';
        $staffPath = route('quotes.show', $quote->id, false);
        $portalPath = route('portal.quotes.show', $quote->id, false);
        $requester = fn (): Collection => $this->recipients->memberships([$quote->requested_by_membership_id]);
        $team = fn (): Collection => $this->recipients->quoteTeam($quote);
        $version = fn (): ?QuoteVersion => QuoteVersion::query()->find($message->string('version_id'));

        return match ($message->type) {
            'quote.requested' => [
                new NotificationContent($message->type, "Nova solicitação de cotação {$number}", "{$company} pediu uma cotação.", $staffPath, 'Abrir a cotação'),
                $team(),
            ],
            'quote.counteroffer_submitted' => [
                new NotificationContent($message->type, "Pedido de revisão na cotação {$number}", "{$company} pediu revisão da proposta.", $staffPath, 'Abrir a cotação'),
                $team(),
            ],
            'quote.commercial_approval_requested' => $this->commercialApproval($message, $quote, $staffPath),
            'quote.commercial_approval_decided' => [
                new NotificationContent(
                    $message->type,
                    ($message->payload['approved'] ?? false) === true ? "Exceção aprovada na cotação {$number}" : "Exceção reprovada na cotação {$number}",
                    ($message->payload['approved'] ?? false) === true ? 'A versão em preparo foi aprovada na aprovação comercial.' : 'A versão em preparo foi reprovada na aprovação comercial.',
                    $staffPath,
                    'Abrir a cotação',
                ),
                $this->recipients->memberships([$version()?->created_by_membership_id]),
            ],
            'quote.proposal_sent' => [
                new NotificationContent($message->type, "Proposta recebida: cotação {$number}", self::proposalBody($message), $portalPath, 'Ver a proposta'),
                $requester(),
            ],
            'quote.proposal_withdrawn' => [
                new NotificationContent($message->type, "Proposta retirada: cotação {$number}", 'O distribuidor retirou a proposta enviada. Aguarde a nova versão.', $portalPath, 'Abrir a cotação'),
                $requester(),
            ],
            'quote.expired' => [
                new NotificationContent($message->type, "Proposta vencida: cotação {$number}", 'A validade da proposta terminou. Você pode pedir a revalidação.', $portalPath, 'Pedir revalidação'),
                $requester(),
            ],
            'quote.stock_hold_expired' => [
                new NotificationContent($message->type, "Reserva de estoque encerrada: cotação {$number}", 'A reserva de estoque da proposta terminou. A proposta continua válida.', $portalPath, 'Abrir a cotação'),
                $requester(),
            ],
            'quote.accepted' => [
                new NotificationContent($message->type, "Proposta aceita: cotação {$number}", self::acceptedBody($message, $company), $staffPath, 'Abrir a cotação'),
                $team(),
            ],
            'quote.rejected' => [
                new NotificationContent($message->type, "Proposta recusada: cotação {$number}", "{$company} recusou a proposta.", $staffPath, 'Abrir a cotação'),
                $team(),
            ],
            'quote.cancelled' => ($message->payload['by'] ?? null) === 'buyer'
                ? [new NotificationContent($message->type, "Cotação {$number} cancelada", "{$company} cancelou a solicitação.", $staffPath, 'Abrir a cotação'), $team()]
                : [new NotificationContent($message->type, "Cotação {$number} encerrada", 'O distribuidor encerrou a cotação.', $portalPath, 'Abrir a cotação'), $requester()],
            'quote.comment_added' => ($message->payload['from_buyer'] ?? false) === true
                ? [new NotificationContent($message->type, "Novo comentário na cotação {$number}", "{$company} comentou na cotação.", $staffPath, 'Ver o comentário'), $team()]
                : [new NotificationContent($message->type, "Novo comentário na cotação {$number}", 'O distribuidor comentou na cotação.', $portalPath, 'Ver o comentário'), $requester()],
            default => null,
        };
    }

    /**
     * @return array{NotificationContent, Collection<int, Membership>}|null
     */
    private function commercialApproval(OutboxMessage $message, Quote $quote, string $path): ?array
    {
        $review = QuoteCommercialReview::query()->where('quote_version_id', $message->string('version_id'))->first();

        if ($review === null || $review->decided_at !== null) {
            return null;
        }

        return [
            new NotificationContent($message->type, "Aprovação comercial pendente: cotação {$quote->number}", 'Uma versão da proposta está fora da política comercial e aguarda decisão.', $path, 'Decidir'),
            $this->recipients->commercialApprovers($review->max_discount_bps, $review->evaluated_by_membership_id),
        ];
    }

    /**
     * @return array{NotificationContent, Collection<int, Membership>}|null
     */
    private function order(OutboxMessage $message): ?array
    {
        $order = Order::query()->find($message->string('order_id'));

        if ($order === null) {
            return null;
        }

        $number = $order->number;
        $orderPath = route('portal.orders.show', $order->id, false);
        $placer = fn (): Collection => $this->recipients->memberships([$order->placed_by_membership_id]);

        return match ($message->type) {
            'order.submitted_for_approval', 'order.approval_level_approved', 'order.approvals_invalidated', 'order.approval_step_reassigned' => $order->commercial_status === CommercialStatus::PendingApproval
                ? [
                    new NotificationContent($message->type, "Pedido {$number} aguarda sua aprovação", 'Total de '.$order->total_cents->format().'. Revise e decida pela fila de aprovação.', route('portal.approvals.show', $order->id, false), 'Revisar o pedido'),
                    $this->recipients->pendingApprovers($order),
                ]
                : null,
            'order.approval_rejected' => [
                new NotificationContent($message->type, "Pedido {$number} rejeitado na aprovação interna", 'O pedido não foi aprovado. Veja o motivo no histórico.', $orderPath, 'Ver o pedido'),
                $placer(),
            ],
            'order.confirmation_pending' => [
                new NotificationContent($message->type, "Pedido {$number} aprovado, aguardando confirmação", self::reason($message, 'O distribuidor não pôde confirmar o pedido agora.'), $orderPath, 'Ver o pedido'),
                $placer(),
            ],
            'order.confirmed' => [
                new NotificationContent($message->type, "Pedido {$number} confirmado", 'O pedido foi confirmado pelo distribuidor, no total de '.$order->total_cents->format().'.', $orderPath, 'Ver o pedido'),
                $placer(),
            ],
            'order.cancelled' => [
                new NotificationContent($message->type, "Pedido {$number} cancelado", 'O pedido foi cancelado.', $orderPath, 'Ver o pedido'),
                $placer(),
            ],
            default => null,
        };
    }

    private static function proposalBody(OutboxMessage $message): string
    {
        $total = $message->payload['total_cents'] ?? null;
        $version = $message->payload['version_number'] ?? null;

        return 'O distribuidor enviou a versão '.(is_int($version) ? $version : '').' da proposta'
            .(is_int($total) ? ', no total de '.Money::ofCents($total)->format() : '').'.';
    }

    private static function acceptedBody(OutboxMessage $message, string $company): string
    {
        $order = Order::query()->find($message->string('order_id'));

        return $order !== null ? "{$company} aceitou a proposta e gerou o pedido {$order->number}." : "{$company} aceitou a proposta.";
    }

    private static function reason(OutboxMessage $message, string $fallback): string
    {
        $reason = $message->string('reason');

        return $reason !== null ? mb_substr($reason, 0, 400) : $fallback;
    }
}
