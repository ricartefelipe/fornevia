<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Portal;

use App\Data\Ordering\Portal\BuyerActor;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Domain\Shared\Quantity;
use App\Enums\Quotes\QuoteRequestKind;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Models\Catalog\Sku;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteRequest;
use App\Models\Quotes\QuoteRequestItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Quotes\QuoteLocks;
use App\Services\Quotes\QuoteStockHolds;
use App\Support\Clock\Clock;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantDatabase;
use InvalidArgumentException;

/**
 * Pedido de revisão (contraproposta) do comprador sobre a proposta exibida
 * (RF-040): mensagem e novas quantidades por item (zero remove o item),
 * nunca preço. A versão deixa de poder ser aceita, a reserva é encerrada e
 * a cotação volta a aguardar proposta. Também vale para pedir a
 * revalidação de uma proposta vencida. Até 20 por cotação.
 */
final readonly class CounterQuoteProposal
{
    public function __construct(
        private BuyerQuotes $quotes,
        private QuoteLocks $locks,
        private QuoteStockHolds $holds,
        private IdempotencyGuard $idempotency,
        private TenantDatabase $db,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @param  array<int, string>  $quantities  número da linha da versão => nova quantidade (pt-BR); ausente mantém
     */
    public function handle(mixed $quoteId, ReviewedQuoteVersion $reviewed, string $message, array $quantities, string $idempotencyKey): Quote
    {
        $actor = $this->quotes->actor();
        $quote = $this->quotes->find($actor, $quoteId);
        $message = trim($message);

        if (mb_strlen($message) < 5 || mb_strlen($message) > 2000) {
            throw new InvalidQuote('Explique o que precisa mudar (de 5 a 2.000 caracteres).', ['field' => 'message']);
        }

        $parsed = [];

        foreach ($quantities as $line => $value) {
            try {
                $parsed[(int) $line] = Quantity::parseLocalized(trim((string) $value) === '' ? '0' : (string) $value);
            } catch (InvalidArgumentException) {
                throw new InvalidQuote("Quantidade inválida na linha {$line}.", ['field' => 'quantities']);
            }
        }

        $payload = ['quote' => $quote->id, 'version' => $reviewed->versionId, 'message' => $message, 'quantities' => array_map(static fn (Quantity $q): string => $q->toDatabase(), $parsed)];

        $this->idempotency->run('quote.counteroffer', $idempotencyKey, $payload, function () use ($actor, $quote, $reviewed, $message, $parsed): array {
            $this->db->asSystem(fn () => $this->counter($actor, $quote, $reviewed, $message, $parsed));
            $this->audit->record('quote.counteroffer_submitted', $quote, ['version_id' => $reviewed->versionId, 'buyer_company_id' => $quote->buyer_company_id], $message);

            return ['resource_type' => 'quote', 'resource_id' => $quote->id, 'response' => []];
        });

        return $quote->refresh();
    }

    /**
     * @param  array<int, Quantity>  $quantities
     */
    private function counter(BuyerActor $actor, Quote $quote, ReviewedQuoteVersion $reviewed, string $message, array $quantities): void
    {
        $locked = $this->locks->lock($quote->id, $actor->company->id);
        $versions = $this->locks->versions($locked);
        $version = $versions->firstWhere('id', $reviewed->versionId);
        $answering = match ($locked->status) {
            QuoteStatus::Proposed => $version?->status === QuoteVersionStatus::Sent && $reviewed->matches($version),
            QuoteStatus::Expired => $version !== null && $version->status === QuoteVersionStatus::Expired
                && $version->id === $versions->whereNotNull('version_number')->sortByDesc('version_number')->first()?->id,
            default => throw new QuoteNotOpen('A cotação não está aguardando sua resposta.'),
        };

        if (! $answering || $version === null) {
            throw new QuoteVersionOutdated('A proposta mudou desde que você abriu esta página. Revise a versão atual antes de pedir revisão.');
        }

        $sequence = (int) QuoteRequest::query()->where('quote_id', $locked->id)->max('sequence');

        if ($sequence > (int) config('quotes.max_counteroffers')) {
            throw new InvalidQuote('Esta cotação atingiu o limite de pedidos de revisão. Solicite uma nova cotação.');
        }

        $items = QuoteItem::query()->where('quote_version_id', $version->id)->orderBy('line_number')->get();
        $skus = Sku::query()->with('salesUnit')->whereKey($items->pluck('sku_id')->all())->get()->keyBy('id');
        $lines = [];

        foreach ($items as $item) {
            $quantity = $quantities[$item->line_number] ?? $item->quantity;

            if ($quantity->isZero()) {
                continue;
            }

            $sku = $skus->get($item->sku_id);

            if ($sku !== null && $sku->purchaseRule()->violations($quantity) !== []) {
                throw new InvalidQuote("A quantidade do item {$item->sku_code} não atende às regras de compra (mínimo ou múltiplo).", ['field' => 'quantities']);
            }

            $lines[] = [$item, $quantity];
        }

        if ($lines === []) {
            throw new InvalidQuote('Mantenha ao menos um item. Para desistir, recuse a proposta.', ['field' => 'quantities']);
        }

        $now = $this->clock->now();

        if ($version->status === QuoteVersionStatus::Sent) {
            $this->holds->release($version, StockHoldEndReason::Countered);
            $version->forceFill(['status' => QuoteVersionStatus::Countered, 'closed_at' => $now])->save();
        }

        $request = new QuoteRequest;
        $request->forceFill([
            'buyer_company_id' => $locked->buyer_company_id,
            'quote_id' => $locked->id,
            'sequence' => $sequence + 1,
            'kind' => QuoteRequestKind::Counteroffer,
            'responds_to_version_id' => $version->id,
            'message' => $message,
            'author_user_id' => $actor->membership->user_id,
            'author_membership_id' => $actor->membership->id,
            'created_at' => $now,
        ])->save();

        foreach ($lines as $index => [$item, $quantity]) {
            (new QuoteRequestItem)->forceFill([
                'buyer_company_id' => $locked->buyer_company_id,
                'quote_request_id' => $request->id,
                'line_number' => $index + 1,
                'sku_id' => $item->sku_id,
                'sku_code' => $item->sku_code,
                'product_name' => $item->product_name,
                'variant_label' => $item->variant_label,
                'unit_code' => $item->unit_code,
                'quantity' => $quantity,
                'created_at' => $now,
            ])->save();
        }

        $locked->forceFill(['status' => QuoteStatus::Requested, 'last_activity_at' => $now])->save();
        $this->outbox->record('quote.counteroffer_submitted', 'quote', $locked->id, ['quote_id' => $locked->id, 'version_id' => $version->id]);
    }
}
