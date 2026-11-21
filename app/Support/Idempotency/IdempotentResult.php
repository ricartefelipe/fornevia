<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

final readonly class IdempotentResult
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public bool $replayed,
        public string $resourceType,
        public string $resourceId,
        public array $response,
    ) {}
}
