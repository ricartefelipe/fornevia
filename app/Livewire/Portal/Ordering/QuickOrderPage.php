<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Ordering\Portal\Exceptions\InvalidCartItem;
use App\Actions\Ordering\Portal\QuickOrder;
use App\Actions\Ordering\Portal\Support\QuickOrderParser;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pedido rápido por SKU: o comprador cola linhas "CÓDIGO quantidade" e
 * recebe o resultado de cada linha. As linhas aceitas vão para o carrinho;
 * as recusadas permanecem no campo para correção.
 */
#[Layout('layouts.app')]
#[Title('Pedido rápido')]
final class QuickOrderPage extends Component
{
    public string $text = '';

    /** @var array{summary: string, added: int, rejected: int, lines: list<array<string, mixed>>}|null */
    #[Locked]
    public ?array $report = null;

    public function submit(QuickOrder $quickOrder): void
    {
        $this->report = null;
        $this->resetErrorBag();

        // Texto além do limite é recusado sem ser interpretado.
        if (mb_strlen($this->text) > QuickOrderParser::MAX_LENGTH) {
            $this->addError('text', 'O texto colado excede o limite de '.number_format(QuickOrderParser::MAX_LENGTH, 0, ',', '.').' caracteres. Divida o pedido em partes.');

            return;
        }

        try {
            $report = $quickOrder->handle($this->text);
        } catch (InvalidCartItem $e) {
            $this->addError('text', $e->getMessage());

            return;
        }

        $this->report = $report->toDisplay();

        // Linhas recusadas ficam no campo para correção; as aceitas saem.
        $rejected = [];

        foreach ($report->lines as $line) {
            if (! $line->isAdded()) {
                $rejected[] = $line->input;
            }
        }

        $this->text = implode("\n", $rejected);
    }

    public function render(): View
    {
        return view('livewire.portal.ordering.quick-order-page', [
            'maxLines' => QuickOrderParser::MAX_LINES,
        ]);
    }
}
