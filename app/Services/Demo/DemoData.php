<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Actions\Credit\OpenCreditAccount;
use App\Actions\Identity\CreateOrganization;
use App\Actions\Ordering\PlaceOrder;
use App\Data\Identity\CreateOrganizationData;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCost;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Models\Ordering\Order;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dados de demonstração: dois distribuidores independentes (isolados por
 * RLS), cada um com equipe, catálogo com preços e estoque, empresas
 * compradoras com usuários, endereços, condições e crédito, e pedidos
 * enviados pelo próprio fluxo de compra (PlaceOrder). Só fora de produção.
 */
final readonly class DemoData
{
    /**
     * Distribuidores: nome, domínio dos e-mails, CNPJ e empresas compradoras.
     */
    public const array TENANTS = [
        [
            'name' => 'Distribuidora Pedra Branca', 'domain' => 'pedrabranca.example', 'document' => '11222333000181',
            'buyers' => [
                ['Construtora Serra Azul', 'Serra Azul Engenharia Ltda', '04252011000110', 'serraazul.example', 'Campinas', 'SP', '13050000'],
                ['Mercado Bom Preço', 'Bom Preço Comércio de Alimentos Ltda', '12ABC34501DE35', 'bompreco.example', 'Ribeirão Preto', 'SP', '14010000'],
            ],
        ],
        [
            'name' => 'Elétrica Horizonte', 'domain' => 'horizonte.example', 'document' => '45997418000153',
            'buyers' => [
                ['Hospital Vida Plena', 'Associação Hospitalar Vida Plena', '60746948000112', 'vidaplena.example', 'Belo Horizonte', 'MG', '30130000'],
                ['Condomínio Parque das Águas', 'Condomínio Residencial Parque das Águas', '33000167000101', 'parquedasaguas.example', 'Contagem', 'MG', '32010000'],
            ],
        ],
    ];

    /** Catálogo: produto, código do SKU, unidade, preço em centavos, custo em centavos e estoque. */
    public const array CATALOG = [
        ['Disjuntor bipolar 32A', 'DJ-32A', 'UN', 4_590, 2_850, '400'],
        ['Disjuntor tripolar 63A', 'DJ-63A', 'UN', 18_900, 11_200, '120'],
        ['Cabo flexível 2,5 mm² (rolo 100 m)', 'CB-25-100', 'RL', 32_900, 21_500, '80'],
        ['Cabo flexível 4 mm² (rolo 100 m)', 'CB-40-100', 'RL', 51_900, 34_000, '50'],
        ['Tomada 2P+T 10A', 'TM-10A', 'UN', 1_290, 610, '1500'],
        ['Luminária LED 40W', 'LM-LED40', 'UN', 15_990, 8_900, '200'],
        ['Eletroduto corrugado 25 mm (rolo 50 m)', 'ED-25-50', 'RL', 8_990, 5_100, '160'],
        ['Fita isolante 19 mm x 20 m', 'FI-19-20', 'UN', 890, 410, '900'],
    ];

    public function __construct(
        private TenantDatabase $db,
        private CreateOrganization $organizations,
        private StockLedger $ledger,
        private Access $access,
        private Clock $clock,
    ) {}

    /**
     * @return list<array{tenant: string, emails: list<string>}>
     */
    public function seed(string $password): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Dados de demonstração não podem ser criados em produção.');
        }

        if (! DB::table('plans')->exists()) {
            (new PlanSeeder)->run();
        }

        // Tudo é conferido antes de gravar: uma execução repetida ou um banco
        // com dados conflitantes é recusado sem deixar dados pela metade.
        $documents = array_column(self::TENANTS, 'document');

        if (Organization::query()->whereIn('document', $documents)->exists()) {
            throw new RuntimeException('Os dados de demonstração já foram criados neste banco.');
        }

        $taken = User::query()->whereIn('email', self::emails())->orderBy('email')->pluck('email')->all();

        if ($taken !== []) {
            throw new RuntimeException('E-mails de demonstração já usados neste banco: '.implode(', ', $taken).'.');
        }

        $summary = [];

        foreach (self::TENANTS as $tenant) {
            $summary[] = $this->tenant($tenant, $password);
        }

        return $summary;
    }

    /**
     * E-mails de todos os usuários de demonstração.
     *
     * @return list<string>
     */
    public static function emails(): array
    {
        $emails = [];

        foreach (self::TENANTS as $tenant) {
            foreach (['dono', 'vendas', 'representante', 'estoque', 'financeiro'] as $local) {
                $emails[] = $local.'@'.$tenant['domain'];
            }

            foreach ($tenant['buyers'] as $buyer) {
                $emails[] = 'compras@'.$buyer[3];
                $emails[] = 'admin@'.$buyer[3];
            }
        }

        return $emails;
    }

    /**
     * @param  array{name: string, domain: string, document: string, buyers: list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>}  $tenant
     * @return array{tenant: string, emails: list<string>}
     */
    private function tenant(array $tenant, string $password): array
    {
        $owner = $this->user('Proprietário '.$tenant['name'], 'dono@'.$tenant['domain'], $password);
        $ownerMembership = $this->organizations->handle($owner, new CreateOrganizationData(
            legalName: $tenant['name'].' Ltda', tradeName: $tenant['name'], document: Cnpj::fromString($tenant['document']), planCode: 'profissional',
        ));
        $tenantId = $ownerMembership->tenant_id;
        $emails = [$owner->email];

        foreach ([[SystemRole::SalesManager, 'Gerente comercial', 'vendas'], [SystemRole::SalesRep, 'Representante', 'representante'],
            [SystemRole::Warehouse, 'Estoque e expedição', 'estoque'], [SystemRole::Finance, 'Financeiro', 'financeiro']] as [$role, $label, $local]) {
            $user = $this->user($label.' · '.$tenant['name'], $local.'@'.$tenant['domain'], $password);
            $this->membership($tenantId, $user, $role);
            $emails[] = $user->email;
        }

        [$skus, $term, $creditTerm] = $this->system($tenantId, fn (): array => $this->catalog());

        foreach ($tenant['buyers'] as $index => [$trade, $legal, $document, $domain, $city, $state, $postalCode]) {
            [$company, $address] = $this->system($tenantId, fn (): array => $this->buyer($trade, $legal, $document, $city, $state, $postalCode, $term, $creditTerm));
            $this->openCredit($tenantId, $ownerMembership, $company);
            $purchaser = $this->user('Compras · '.$trade, 'compras@'.$domain, $password);
            $membership = $this->membership($tenantId, $purchaser, SystemRole::Purchaser, $company);
            $admin = $this->user('Administração · '.$trade, 'admin@'.$domain, $password);
            $this->membership($tenantId, $admin, SystemRole::BuyerAdmin, $company);
            $emails[] = $purchaser->email;
            $emails[] = $admin->email;

            // Um pedido por empresa, pelo fluxo real de compra.
            $lines = $index === 0 ? [[$skus[0], '20'], [$skus[2], '3']] : [[$skus[4], '120'], [$skus[7], '30']];
            $this->order($tenantId, $membership, $company, $address, $index === 0 ? $creditTerm : $term, $lines);
        }

        return ['tenant' => $tenant['name'], 'emails' => $emails];
    }

    /**
     * @return array{0: list<Sku>, 1: PaymentTerm, 2: PaymentTerm}
     */
    private function catalog(): array
    {
        $warehouse = new Warehouse;
        $warehouse->forceFill([
            'branch_id' => Branch::query()->where('is_headquarters', true)->value('id'),
            'code' => 'CD1', 'name' => 'Centro de distribuição', 'priority' => 10, 'allows_sales' => true, 'is_active' => true,
        ])->save();

        $list = new PriceList;
        $list->forceFill(['code' => 'PADRAO', 'name' => 'Tabela padrão', 'scope' => 'default', 'valid_from' => $this->clock->now()->startOfYear()->toDateString(), 'status' => 'active'])->save();
        $skus = [];

        foreach (self::CATALOG as [$name, $code, $unitCode, $price, $cost, $stock]) {
            $unit = UnitOfMeasure::query()->where('code', $unitCode)->firstOrFail();
            $product = new Product;
            $product->forceFill([
                'name' => $name, 'slug' => Str::slug($name), 'base_unit_id' => $unit->id, 'status' => 'active', 'visibility' => 'public',
            ])->save();
            $sku = new Sku;
            $sku->forceFill([
                'product_id' => $product->id, 'sales_unit_id' => $unit->id, 'code' => $code, 'conversion_factor' => '1',
                'min_order_quantity' => '1', 'order_multiple' => '1', 'status' => 'active',
            ])->save();
            (new SkuCost)->forceFill(['sku_id' => $sku->id, 'cost_cents' => $cost, 'effective_from' => $this->clock->now()->startOfYear()])->save();
            $item = new PriceListItem;
            $item->forceFill(['price_list_id' => $list->id, 'sku_id' => $sku->id, 'unit_price_cents' => $price])->save();
            $this->ledger->receive($warehouse->id, $sku->id, Quantity::of($stock), 'Saldo inicial da demonstração');
            $skus[] = $sku;
        }

        return [
            $skus,
            PaymentTerm::query()->where('code', 'AVISTA')->firstOrFail(),
            PaymentTerm::query()->where('code', '28D')->firstOrFail(),
        ];
    }

    /**
     * @return array{0: BuyerCompany, 1: BuyerAddress}
     */
    private function buyer(string $trade, string $legal, string $document, string $city, string $state, string $postalCode, PaymentTerm $term, PaymentTerm $creditTerm): array
    {
        $company = new BuyerCompany;
        $company->forceFill(['legal_name' => $legal, 'trade_name' => $trade, 'document' => $document])->save();
        $address = new BuyerAddress;
        $address->forceFill([
            'buyer_company_id' => $company->id, 'kind' => 'shipping', 'label' => 'Entrega principal', 'street' => 'Avenida das Indústrias',
            'number' => '1500', 'district' => 'Distrito Industrial', 'city' => $city, 'state' => $state, 'postal_code' => $postalCode,
            'is_default' => true, 'is_active' => true,
        ])->save();

        foreach ([[$term, true], [$creditTerm, false]] as [$allowed, $default]) {
            (new BuyerPaymentTerm)->forceFill(['buyer_company_id' => $company->id, 'payment_term_id' => $allowed->id, 'is_default' => $default])->save();
        }

        return [$company, $address];
    }

    /** Conta de crédito pelo fluxo real: histórico do limite e auditoria. */
    private function openCredit(string $tenantId, Membership $owner, BuyerCompany $company): void
    {
        $this->as($owner, static fn () => app(OpenCreditAccount::class)->handle($company, Money::ofCents(5_000_000), 5, 'Limite inicial da demonstração'));
    }

    /**
     * @param  list<array{0: Sku, 1: string}>  $lines
     */
    private function order(string $tenantId, Membership $membership, BuyerCompany $company, BuyerAddress $address, PaymentTerm $term, array $lines): Order
    {
        $cart = $this->system($tenantId, static function () use ($company, $membership, $lines): Cart {
            $cart = new Cart;
            $cart->forceFill(['buyer_company_id' => $company->id, 'membership_id' => $membership->id, 'status' => 'open'])->save();

            foreach ($lines as [$sku, $quantity]) {
                (new CartItem)->forceFill(['cart_id' => $cart->id, 'buyer_company_id' => $company->id, 'sku_id' => $sku->id, 'quantity' => $quantity])->save();
            }

            return $cart;
        });

        $data = new PlaceOrderData(cartId: $cart->id, idempotencyKey: 'demo-'.$cart->id, shippingAddressId: $address->id, paymentTermId: $term->id);

        return $this->as($membership, static fn (): Order => app(PlaceOrder::class)->handle($data));
    }

    /**
     * Executa como a associação, com as permissões dela.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function as(Membership $membership, Closure $callback): mixed
    {
        return $this->db->run($membership->executionContext(), function () use ($membership, $callback): mixed {
            $this->access->bindMembership(Membership::query()->with('role')->findOrFail($membership->id));

            return $callback();
        });
    }

    private function user(string $name, string $email, string $password): User
    {
        $user = new User;
        $user->forceFill(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'email_verified_at' => $this->clock->now()])->save();

        return $user;
    }

    private function membership(string $tenantId, User $user, SystemRole $role, ?BuyerCompany $company = null): Membership
    {
        return $this->system($tenantId, static function () use ($user, $role, $company): Membership {
            $membership = new Membership;
            $membership->forceFill([
                'user_id' => $user->id,
                'kind' => $company === null ? MembershipKind::Staff : MembershipKind::Buyer,
                'buyer_company_id' => $company?->id,
                'role_id' => Role::query()->where('key', $role->value)->value('id'),
                'status' => MembershipStatus::Active,
            ])->save();

            return $membership;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function system(string $tenantId, Closure $callback): mixed
    {
        return $this->db->run(ExecutionContext::system($tenantId), $callback);
    }
}
