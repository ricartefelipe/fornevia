<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Buyers\SaveBuyerCompany;
use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\ReceiveStock;
use App\Actions\Ordering\Staff\CancelStaffOrderItems;
use App\Data\Buyers\BuyerCompanyData;
use App\Data\Inventory\StockBalance;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Sku;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Order;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Idempotency\IdempotentResult;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Escrita da API v1 (RF-110): cadastro de empresa compradora, entrada e
 * ajuste de estoque e cancelamento de pedido. Cada operação exige a
 * permissão própria concedida ao cliente da API e o cabeçalho
 * Idempotency-Key: a repetição com o mesmo conteúdo devolve a mesma
 * resposta sem repetir o efeito; com outro conteúdo, é recusada (409). As
 * regras de negócio são as mesmas das telas (as Actions do módulo).
 */
final readonly class ApiWrites
{
    public function __construct(
        private Access $access,
        private IdempotencyGuard $idempotency,
        private ApiResources $resources,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function createBuyer(?string $key, array $input): IdempotentResult
    {
        $this->access->authorize(Permission::BuyersManage);

        try {
            $document = Cnpj::fromString(is_string($input['document'] ?? null) ? $input['document'] : '');
            $documentErrors = [];
        } catch (BusinessRuleViolation|InvalidArgumentException) {
            $document = null;
            $documentErrors = ['document' => ['CNPJ inválido.']];
        }

        $fields = self::fields($input, ['legal_name' => true, 'trade_name' => true, 'document' => true, 'state_registration' => false, 'email' => false, 'phone' => false], $documentErrors);
        assert($document !== null);

        // Conteúdo normalizado: o mesmo CNPJ com ou sem pontuação é a mesma operação.
        $payload = [...$fields, 'document' => $document->digits];

        return $this->idempotency->run('api.buyers.create', self::key($key), $payload, function () use ($fields, $document): array {
            $company = app(SaveBuyerCompany::class)->handle(null, new BuyerCompanyData(
                legalName: (string) $fields['legal_name'],
                tradeName: (string) $fields['trade_name'],
                document: $document,
                stateRegistration: $fields['state_registration'] ?? null,
                email: $fields['email'] ?? null,
                phone: $fields['phone'] ?? null,
            ));

            return ['resource_type' => 'buyer_company', 'resource_id' => $company->id, 'response' => $this->resources->buyer($company)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function receiveStock(?string $key, array $input): IdempotentResult
    {
        $this->access->authorize(Permission::InventoryManage);
        $fields = self::fields($input, ['warehouse_code' => true, 'sku_code' => true, 'quantity' => true, 'note' => false]);

        // Cadastro conferido dentro da operação: uma repetição devolve a
        // resposta guardada mesmo que o depósito ou o SKU tenham mudado depois.
        return $this->idempotency->run('api.stock.receive', self::key($key), self::stockPayload($fields, 'quantity'), function () use ($fields): array {
            [$warehouse, $sku, $quantity] = self::stockTarget($fields, 'quantity');
            $balance = app(ReceiveStock::class)->handle($warehouse, $sku, $quantity, $fields['note'] ?? null);

            return ['resource_type' => 'stock_level', 'resource_id' => (string) Str::uuid7(), 'response' => self::balance($balance, $warehouse, $sku)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function adjustStock(?string $key, array $input): IdempotentResult
    {
        $this->access->authorize(Permission::InventoryManage);
        $fields = self::fields($input, ['warehouse_code' => true, 'sku_code' => true, 'delta' => true, 'reason' => true]);

        return $this->idempotency->run('api.stock.adjust', self::key($key), self::stockPayload($fields, 'delta'), function () use ($fields): array {
            [$warehouse, $sku, $delta] = self::stockTarget($fields, 'delta', signed: true);
            $balance = app(AdjustStock::class)->handle($warehouse, $sku, $delta, (string) $fields['reason']);

            return ['resource_type' => 'stock_level', 'resource_id' => (string) Str::uuid7(), 'response' => self::balance($balance, $warehouse, $sku)];
        });
    }

    /**
     * Cancela itens (quantidade por item, na unidade de venda) ou, sem
     * itens, tudo o que ainda for cancelável.
     *
     * @param  array<string, mixed>  $input
     */
    public function cancelOrder(?string $key, string $orderId, array $input): IdempotentResult
    {
        $this->access->authorize(Permission::OrdersManage);
        $reason = $input['reason'] ?? null;
        $items = $input['items'] ?? null;
        $errors = [];

        if (! is_string($reason) || mb_strlen(trim($reason)) < 5 || mb_strlen(trim($reason)) > 500) {
            $errors['reason'] = ['Informe o motivo do cancelamento (5 a 500 caracteres).'];
        }

        $quantities = null;

        if ($items !== null) {
            $quantities = [];

            if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 200) {
                $errors['items'] = ['Informe de 1 a 200 itens, cada um com id e quantity.'];
            } else {
                foreach ($items as $i => $item) {
                    $id = is_array($item) ? ($item['id'] ?? null) : null;

                    try {
                        $quantity = Quantity::of(is_array($item) && is_string($item['quantity'] ?? null) ? $item['quantity'] : '');
                    } catch (InvalidArgumentException|BusinessRuleViolation) {
                        $quantity = null;
                    }

                    if (! is_string($id) || ! Str::isUuid($id) || $quantity === null || ! $quantity->isPositive()) {
                        $errors["items.{$i}"] = ['Cada item precisa de id (UUID) e quantity positiva em texto (ex.: "2.5").'];

                        continue;
                    }

                    if (isset($quantities[strtolower($id)])) {
                        $errors["items.{$i}"] = ['Item repetido: informe cada item uma única vez.'];

                        continue;
                    }

                    $quantities[strtolower($id)] = $quantity;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $order = UntrustedId::uuid($orderId, Order::class);
        // Conteúdo normalizado: maiúsculas no id e "1" ou "1.0" são a mesma operação.
        $payload = [
            'order_id' => strtolower($order),
            'reason' => trim((string) $reason),
            'items' => $quantities === null ? null : array_map(static fn (Quantity $q): string => $q->toDatabase(), $quantities),
        ];

        return $this->idempotency->run('api.orders.cancel', self::key($key), $payload, function () use ($order, $quantities, $reason): array {
            $outcome = app(CancelStaffOrderItems::class)->handle($order, $quantities, trim((string) $reason));

            return ['resource_type' => 'order', 'resource_id' => $outcome->order->id, 'response' => $this->resources->order($outcome->order)];
        });
    }

    private static function key(?string $key): string
    {
        if ($key === null || trim($key) === '') {
            throw ValidationException::withMessages(['Idempotency-Key' => ['Informe o cabeçalho Idempotency-Key (8 a 100 caracteres).']]);
        }

        return trim($key);
    }

    /**
     * Só os campos declarados, como texto; desconhecido ou de outro tipo é
     * erro de validação, para não ser ignorado em silêncio.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, bool>  $allowed  campo => obrigatório
     * @param  array<string, list<string>>  $errors  erros já apurados, devolvidos junto
     * @return array<string, string|null>
     */
    private static function fields(array $input, array $allowed, array $errors = []): array
    {
        $fields = [];

        foreach ($input as $name => $value) {
            if (! array_key_exists((string) $name, $allowed)) {
                $errors[(string) $name] = ['Campo não permitido nesta operação.'];
            } elseif ($value !== null && (! is_string($value) || mb_strlen($value) > 500)) {
                $errors[(string) $name] = ['Informe um texto de até 500 caracteres.'];
            } else {
                $fields[(string) $name] = $value === null ? null : trim($value);
            }
        }

        foreach ($allowed as $name => $required) {
            if ($required && ($fields[$name] ?? '') === '') {
                $errors[$name] ??= ['Campo obrigatório.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($fields);

        return $fields;
    }

    /**
     * Conteúdo normalizado da operação de estoque (códigos em maiúsculas,
     * quantidade com 3 casas quando válida).
     *
     * @param  array<string, string|null>  $fields
     * @return array<string, string|null>
     */
    private static function stockPayload(array $fields, string $quantityField): array
    {
        try {
            $quantity = Quantity::of((string) $fields[$quantityField])->toDatabase();
        } catch (InvalidArgumentException|BusinessRuleViolation) {
            $quantity = $fields[$quantityField];
        }

        return [
            ...$fields,
            'warehouse_code' => mb_strtoupper((string) $fields['warehouse_code']),
            'sku_code' => mb_strtoupper((string) $fields['sku_code']),
            $quantityField => $quantity,
        ];
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return array{0: Warehouse, 1: Sku, 2: Quantity}
     */
    private static function stockTarget(array $fields, string $quantityField, bool $signed = false): array
    {
        // Códigos guardados em maiúsculas: a busca usa o índice único.
        $warehouse = Warehouse::query()->where('code', mb_strtoupper((string) $fields['warehouse_code']))->first();
        $sku = Sku::query()->where('code', mb_strtoupper((string) $fields['sku_code']))->first();
        $errors = [];

        try {
            $quantity = Quantity::of((string) $fields[$quantityField]);
        } catch (InvalidArgumentException|BusinessRuleViolation) {
            $quantity = null;
        }

        if ($warehouse === null) {
            $errors['warehouse_code'] = ['Depósito não cadastrado.'];
        }

        if ($sku === null) {
            $errors['sku_code'] = ['SKU não cadastrado.'];
        }

        if ($quantity === null || ($signed ? $quantity->isZero() : ! $quantity->isPositive())) {
            $errors[$quantityField] = [$signed ? 'Informe a variação, diferente de zero, em texto (ex.: "-3" ou "2.5").' : 'Informe a quantidade positiva em texto (ex.: "10" ou "2.5").'];
        }

        if ($errors !== [] || $warehouse === null || $sku === null || $quantity === null) {
            throw ValidationException::withMessages($errors);
        }

        return [$warehouse, $sku, $quantity];
    }

    /**
     * @return array<string, string>
     */
    private static function balance(StockBalance $balance, Warehouse $warehouse, Sku $sku): array
    {
        return [
            'warehouse_code' => $warehouse->code, 'sku_code' => $sku->code,
            'on_hand' => $balance->onHand->toDatabase(), 'reserved' => $balance->reserved->toDatabase(), 'available' => $balance->available()->toDatabase(),
        ];
    }
}
