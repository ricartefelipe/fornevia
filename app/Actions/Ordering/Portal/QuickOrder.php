<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Exceptions\InvalidCartItem;
use App\Actions\Ordering\Portal\Support\CartWriter;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Actions\Ordering\Portal\Support\PurchasableSkus;
use App\Actions\Ordering\Portal\Support\QuickOrderParser;
use App\Data\Ordering\Portal\CartAdditionLine;
use App\Data\Ordering\Portal\CartAdditionReport;
use App\Enums\Identity\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Pedido rápido por SKU (RF-060): linhas "CÓDIGO quantidade" coladas pelo
 * comprador são conferidas uma a uma (SKU visível à empresa, regra de
 * compra, preço vigente) e as válidas entram no carrinho, somadas às
 * quantidades já existentes. O relatório informa o resultado de cada linha.
 *
 * As linhas válidas são gravadas na mesma transação; se o total do
 * carrinho ultrapassar o limite de cálculo, nada é gravado.
 */
final readonly class QuickOrder
{
    public function __construct(
        private PortalBuyer $buyer,
        private PurchasableSkus $skus,
        private CartWriter $writer,
        private QuickOrderParser $parser,
    ) {}

    /**
     * @throws InvalidCartItem texto inválido (field = text) ou carrinho acima do limite (field = cart)
     */
    public function handle(string $text): CartAdditionReport
    {
        $actor = $this->buyer->require(Permission::PortalCart);
        $parsed = $this->parser->parse($text);

        return DB::transaction(function () use ($actor, $parsed): CartAdditionReport {
            $cart = $this->writer->lockOpenCart($actor) ?? throw InvalidCartItem::because('Carrinho indisponível.', null, 'cart');
            $codes = array_values(array_filter(array_column($parsed, 'code'), is_string(...)));
            $skus = $this->skus->byCodes($actor->company, $codes);
            $lines = [];

            foreach ($parsed as $row) {
                if ($row['error'] !== null || $row['code'] === null || $row['quantity'] === null) {
                    $lines[] = CartAdditionLine::rejected($row['line'], $row['input'], $row['error'] ?? 'Linha fora do formato "CÓDIGO quantidade".');

                    continue;
                }

                $sku = $skus[$row['code']] ?? null;

                if ($sku === null) {
                    $lines[] = CartAdditionLine::rejected($row['line'], $row['input'], "Código {$row['code']} não encontrado entre os produtos disponíveis para a sua empresa.", $row['code']);

                    continue;
                }

                $unit = $sku->salesUnit->code;

                if ($row['unit'] !== null && $row['unit'] !== mb_strtoupper($unit)) {
                    $lines[] = CartAdditionLine::rejected($row['line'], $row['input'], "O item {$sku->code} é vendido em {$unit}, não em {$row['unit']}.", $sku->code, $sku->product->name);

                    continue;
                }

                try {
                    $quantity = $this->writer->parseQuantity($sku, $row['quantity']);
                    $this->writer->add($cart, $actor->company, $sku, $quantity);
                } catch (InvalidCartItem $e) {
                    $lines[] = new CartAdditionLine(
                        $row['line'], $row['input'], CartAdditionLine::REJECTED, null, $sku->code, $sku->product->name, null, $unit,
                        $e->messages(), $e->suggestion(),
                    );

                    continue;
                }

                $lines[] = new CartAdditionLine($row['line'], $row['input'], CartAdditionLine::ADDED, $sku->id, $sku->code, $sku->product->name, $quantity, $unit);
            }

            return $this->writer->report($cart, $actor->company, $lines);
        });
    }
}
