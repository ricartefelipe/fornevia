<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Domain\Integrations\Exceptions\InvalidIntegration;
use Illuminate\Contracts\Container\Container;

/**
 * Tipos de importação disponíveis.
 */
final readonly class ImportKinds
{
    public const array CLASSES = [
        'customers' => CustomersImport::class,
        'products' => ProductsImport::class,
        'stock' => StockImport::class,
    ];

    public function __construct(private Container $container) {}

    public function get(string $key): ImportKind
    {
        $class = self::CLASSES[$key] ?? throw new InvalidIntegration('Escolha o tipo de importação.', ['field' => 'kind']);

        return $this->container->make($class);
    }
}
