<?php

declare(strict_types=1);

namespace App\Data\Approvals;

use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\Order;

/**
 * Versão do pedido que o aprovador viu ao decidir: o nível em análise e a
 * impressão do que é aprovado (ApprovalFingerprint). A decisão só vale se o
 * pedido, sob lock, ainda estiver nessa versão.
 */
final readonly class ReviewedApproval
{
    public function __construct(
        public string $stepId,
        public string $fingerprint,
    ) {}

    public static function of(Order $order, ?OrderApprovalStep $current): self
    {
        return new self($current->id ?? '', (string) $order->approval_fingerprint);
    }

    public function matches(Order $order, OrderApprovalStep $current): bool
    {
        return $this->stepId === $current->id
            && $this->fingerprint !== ''
            && hash_equals((string) $order->approval_fingerprint, $this->fingerprint);
    }
}
