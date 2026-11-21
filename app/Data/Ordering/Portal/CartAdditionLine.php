<?php

declare(strict_types=1);

namespace App\Data\Ordering\Portal;

use App\Domain\Shared\Quantity;

/**
 * Resultado de uma linha adicionada (ou recusada) ao carrinho pelo pedido
 * rápido, por uma lista de compra ou pela recompra.
 *
 * - added: adicionada na quantidade pedida;
 * - adjusted: adicionada na quantidade válida mais próxima (a regra de
 *   compra do SKU mudou desde a lista ou o pedido de origem);
 * - rejected: não adicionada (messages explica o motivo).
 *
 * warnings traz avisos de itens adicionados (ex.: estoque insuficiente no
 * momento), que não impedem a inclusão mas aparecem no carrinho.
 */
final readonly class CartAdditionLine
{
    public const string ADDED = 'added';

    public const string ADJUSTED = 'adjusted';

    public const string REJECTED = 'rejected';

    /**
     * @param  list<string>  $messages
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?int $lineNumber,
        public string $input,
        public string $status,
        public ?string $skuId = null,
        public ?string $skuCode = null,
        public ?string $productName = null,
        public ?Quantity $quantity = null,
        public ?string $unitCode = null,
        public array $messages = [],
        public ?Quantity $suggestion = null,
        public array $warnings = [],
    ) {}

    public static function rejected(?int $lineNumber, string $input, string $message, ?string $skuCode = null, ?string $productName = null, ?Quantity $suggestion = null, ?string $unitCode = null): self
    {
        return new self($lineNumber, $input, self::REJECTED, null, $skuCode, $productName, null, $unitCode, [$message], $suggestion);
    }

    public function isAdded(): bool
    {
        return $this->status !== self::REJECTED;
    }

    /**
     * @param  list<string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self(
            $this->lineNumber, $this->input, $this->status, $this->skuId, $this->skuCode, $this->productName,
            $this->quantity, $this->unitCode, $this->messages, $this->suggestion, $warnings,
        );
    }

    /**
     * Representação para exibição (estado da tela entre requisições).
     *
     * @return array{line: int|null, input: string, status: string, status_label: string, sku_code: string|null, product: string|null, quantity: string|null, unit: string|null, messages: list<string>, warnings: list<string>, suggestion: string|null}
     */
    public function toDisplay(): array
    {
        return [
            'line' => $this->lineNumber,
            'input' => $this->input,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'sku_code' => $this->skuCode,
            'product' => $this->productName,
            'quantity' => $this->quantity?->format(),
            'unit' => $this->unitCode,
            'messages' => $this->messages,
            'warnings' => $this->warnings,
            'suggestion' => $this->suggestion?->format(),
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ADDED => 'Adicionado',
            self::ADJUSTED => 'Adicionado com ajuste',
            default => 'Não adicionado',
        };
    }
}
