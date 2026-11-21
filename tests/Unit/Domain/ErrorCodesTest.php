<?php

declare(strict_types=1);

use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Domain\Catalog\Exceptions\CategoryCycle;
use App\Domain\Catalog\Exceptions\InvalidMediaFile;
use App\Domain\Shared\BusinessRuleViolation;
use Illuminate\Support\Str;

/*
| Códigos de erro das regras de negócio são contrato com clientes da API e
| com as telas: estáveis, em snake_case e, salvo o mesmo significado em
| módulos distintos, únicos.
*/

/**
 * @return array<class-string<BusinessRuleViolation>, string>
 */
function domainErrorCodes(): array
{
    $codes = [];
    $root = dirname(__DIR__, 3).'/app/Domain';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\Domain\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), $root.'/'));

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(BusinessRuleViolation::class)) {
            continue;
        }

        /** @var BusinessRuleViolation $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $codes[$class] = $instance->errorCode();
    }

    ksort($codes);

    return $codes;
}

it('mantém os códigos de erro das regras de negócio', function (): void {
    expect(domainErrorCodes())->toBe([
        'App\Domain\Approvals\Exceptions\ApprovalNotAllowed' => 'approval_not_allowed',
        'App\Domain\Approvals\Exceptions\ApprovalOutdated' => 'approval_outdated',
        'App\Domain\Approvals\Exceptions\BudgetExceeded' => 'budget_exceeded',
        'App\Domain\Approvals\Exceptions\InvalidApprovalRule' => 'invalid_approval_rule',
        'App\Domain\Approvals\Exceptions\InvalidBudget' => 'invalid_budget',
        'App\Domain\Approvals\Exceptions\OrderNotAwaitingApproval' => 'order_not_awaiting_approval',
        'App\Domain\Buyers\Exceptions\BuyerCompanyAlreadyRegistered' => 'buyer_company_already_registered',
        'App\Domain\Buyers\Exceptions\BuyerCompanyStatusUnchanged' => 'buyer_company_status_unchanged',
        'App\Domain\Buyers\Exceptions\BuyerUnitCodeTaken' => 'buyer_unit_code_taken',
        'App\Domain\Buyers\Exceptions\BuyerUnitMismatch' => 'buyer_unit_mismatch',
        'App\Domain\Buyers\Exceptions\CostCenterCodeTaken' => 'cost_center_code_taken',
        'App\Domain\Buyers\Exceptions\CustomerGroupCodeTaken' => 'customer_group_code_taken',
        'App\Domain\Buyers\Exceptions\CustomerGroupInUse' => 'customer_group_in_use',
        'App\Domain\Buyers\Exceptions\InvalidAddress' => 'invalid_address',
        'App\Domain\Buyers\Exceptions\InvalidPaymentTerms' => 'invalid_payment_terms',
        'App\Domain\Buyers\Exceptions\InvalidRecordField' => 'invalid_record_field',
        'App\Domain\Buyers\Exceptions\InvalidSalesRepresentative' => 'invalid_sales_representative',
        'App\Domain\Buyers\Exceptions\JustificationRequired' => 'justification_required',
        'App\Domain\Catalog\Exceptions\CatalogIdentifierInUse' => 'catalog_identifier_in_use',
        'App\Domain\Catalog\Exceptions\CatalogItemArchived' => 'catalog_item_archived',
        'App\Domain\Catalog\Exceptions\CategoryCycle' => 'category_cycle',
        'App\Domain\Catalog\Exceptions\InvalidCatalogData' => 'invalid_catalog_data',
        'App\Domain\Catalog\Exceptions\InvalidMediaFile' => 'invalid_media_file',
        'App\Domain\Catalog\Exceptions\InvalidProductStatusTransition' => 'invalid_product_status_transition',
        'App\Domain\Catalog\Exceptions\ProductHasHistory' => 'product_has_history',
        'App\Domain\Catalog\Exceptions\ProductRequiresActiveSku' => 'product_requires_active_sku',
        'App\Domain\Catalog\Exceptions\SkuPackagingInUse' => 'sku_packaging_in_use',
        'App\Domain\Catalog\Exceptions\UnitDecimalsInUse' => 'unit_decimals_in_use',
        'App\Domain\Credit\Exceptions\CreditAccountAlreadyOpen' => 'credit_account_already_open',
        'App\Domain\Credit\Exceptions\CreditAccountNotOpen' => 'credit_account_not_open',
        'App\Domain\Credit\Exceptions\CreditAccountUnchanged' => 'credit_account_unchanged',
        'App\Domain\Credit\Exceptions\CreditExceptionNotActive' => 'credit_exception_not_active',
        'App\Domain\Credit\Exceptions\CreditNotApproved' => 'credit_not_approved',
        'App\Domain\Credit\Exceptions\InvalidCreditValue' => 'invalid_credit_value',
        'App\Domain\Identity\Exceptions\AlreadyMember' => 'already_member',
        'App\Domain\Identity\Exceptions\ContextUnavailable' => 'context_unavailable',
        'App\Domain\Identity\Exceptions\InvalidInvitation' => 'invalid_invitation',
        'App\Domain\Identity\Exceptions\InvitationEmailMismatch' => 'invitation_email_mismatch',
        'App\Domain\Identity\Exceptions\LastOwnerRequired' => 'last_owner_required',
        'App\Domain\Identity\Exceptions\RoleKindMismatch' => 'role_kind_mismatch',
        'App\Domain\Inventory\Exceptions\InsufficientStock' => 'insufficient_stock',
        'App\Domain\Inventory\Exceptions\InvalidInventoryOperation' => 'invalid_inventory_operation',
        'App\Domain\Inventory\Exceptions\WarehouseCodeTaken' => 'warehouse_code_taken',
        'App\Domain\Inventory\Exceptions\WarehouseHasHistory' => 'warehouse_has_history',
        'App\Domain\Ordering\Exceptions\CartAlreadyConverted' => 'cart_already_converted',
        'App\Domain\Ordering\Exceptions\CartNotReady' => 'cart_not_ready',
        'App\Domain\Ordering\Exceptions\InvalidCartItem' => 'invalid_cart_item',
        'App\Domain\Ordering\Exceptions\InvalidCheckout' => 'invalid_checkout',
        'App\Domain\Ordering\Exceptions\InvalidShoppingList' => 'invalid_shopping_list',
        'App\Domain\Ordering\Exceptions\OrderNotCancellable' => 'order_not_cancellable',
        'App\Domain\Ordering\Exceptions\PricesChanged' => 'prices_changed',
        'App\Domain\Platform\Exceptions\ModuleNotIncluded' => 'module_not_included',
        'App\Domain\Platform\Exceptions\OrganizationAlreadyRegistered' => 'organization_already_registered',
        'App\Domain\Platform\Exceptions\PlanLimitReached' => 'plan_limit_reached',
        'App\Domain\Platform\Exceptions\PlanUnavailable' => 'plan_unavailable',
        'App\Domain\Platform\Exceptions\SubscriptionInactive' => 'subscription_inactive',
        'App\Domain\Pricing\Exceptions\InvalidMinimumMargin' => 'invalid_minimum_margin',
        'App\Domain\Pricing\Exceptions\InvalidPaymentTerm' => 'invalid_payment_term',
        'App\Domain\Pricing\Exceptions\InvalidPriceList' => 'invalid_price_list',
        'App\Domain\Pricing\Exceptions\InvalidPriceListTarget' => 'invalid_price_list_target',
        'App\Domain\Pricing\Exceptions\InvalidPriceListTransition' => 'invalid_price_list_transition',
        'App\Domain\Pricing\Exceptions\InvalidPriceTiers' => 'invalid_price_tiers',
        'App\Domain\Pricing\Exceptions\InvalidQuantity' => 'invalid_quantity',
        'App\Domain\Pricing\Exceptions\InvalidUnitPrice' => 'invalid_unit_price',
        'App\Domain\Pricing\Exceptions\InvalidValidityPeriod' => 'invalid_validity_period',
        'App\Domain\Pricing\Exceptions\JustificationRequired' => 'justification_required',
        'App\Domain\Pricing\Exceptions\NegotiatedPriceNotActive' => 'negotiated_price_not_active',
        'App\Domain\Pricing\Exceptions\NegotiatedPriceOverlap' => 'negotiated_price_overlap',
        'App\Domain\Pricing\Exceptions\PaymentTermCodeTaken' => 'payment_term_code_taken',
        'App\Domain\Pricing\Exceptions\PriceListCodeTaken' => 'price_list_code_taken',
        'App\Domain\Pricing\Exceptions\PriceListNotEditable' => 'price_list_not_editable',
        'App\Domain\Pricing\Exceptions\PriceNotAvailable' => 'price_not_available',
        'App\Domain\Pricing\Exceptions\SkuNotPriceable' => 'sku_not_priceable',
        'App\Domain\Pricing\Exceptions\SkuPackagingChanged' => 'sku_packaging_changed',
        'App\Domain\Shared\AmountOutOfRange' => 'amount_out_of_range',
        'App\Domain\Shared\InvalidDocument' => 'invalid_document',
    ]);
});

it('usa códigos em snake_case e repete código apenas para o mesmo significado', function (): void {
    $codes = domainErrorCodes();

    foreach ($codes as $class => $code) {
        expect($code)->toMatch('/^[a-z]+(_[a-z]+)*$/', $class);
    }

    $repeated = array_keys(array_filter(array_count_values($codes), static fn (int $count): bool => $count > 1));

    expect($repeated)->toBe(['justification_required']);
});

it('descreve violações de catálogo sem dados internos', function (): void {
    expect(CatalogItemArchived::sku()->details())->toBe(['subject' => 'sku'])
        ->and(CategoryCycle::create()->getMessage())->toContain('subcategorias')
        ->and(InvalidMediaFile::empty()->getMessage())->toBe('O arquivo enviado está vazio ou não pôde ser lido.');
});
