<?php

declare(strict_types=1);

namespace App\Data\Ordering\Portal;

/**
 * Relatório por linha de uma inclusão em lote no carrinho.
 */
final readonly class CartAdditionReport
{
    /**
     * @param  list<CartAdditionLine>  $lines
     */
    public function __construct(public array $lines) {}

    public function addedCount(): int
    {
        return count(array_filter($this->lines, static fn (CartAdditionLine $line): bool => $line->isAdded()));
    }

    public function rejectedCount(): int
    {
        return count($this->lines) - $this->addedCount();
    }

    /**
     * @return array{summary: string, added: int, rejected: int, lines: list<array<string, mixed>>}
     */
    public function toDisplay(): array
    {
        return [
            'summary' => $this->summary(),
            'added' => $this->addedCount(),
            'rejected' => $this->rejectedCount(),
            'lines' => array_map(static fn (CartAdditionLine $line): array => $line->toDisplay(), $this->lines),
        ];
    }

    public function summary(): string
    {
        $added = $this->addedCount();
        $rejected = $this->rejectedCount();

        $parts = [$added === 1 ? '1 item adicionado ao carrinho' : "{$added} itens adicionados ao carrinho"];

        if ($rejected > 0) {
            $parts[] = $rejected === 1 ? '1 não adicionado' : "{$rejected} não adicionados";
        }

        return implode('; ', $parts).'.';
    }
}
