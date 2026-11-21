<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Models\Ordering\Order;
use Carbon\CarbonImmutable;

/**
 * Linha da fila de aprovação.
 */
final readonly class InboxEntry
{
    /**
     * @param  PendingStepState|null  $pending  nível pendente (pedidos ainda em aprovação)
     * @param  string|null  $principalName  titular quando o ator decide por delegação
     * @param  InboxDecision|null  $decision  última decisão do ator (aba de decididos)
     */
    public function __construct(
        public Order $order,
        public ?string $requesterName,
        public ?string $costCenterName,
        public int $levelCount,
        public ?PendingStepState $pending,
        public ?string $principalName,
        public ?InboxDecision $decision,
    ) {}

    public function submittedAt(): CarbonImmutable
    {
        return $this->order->submitted_at ?? $this->order->created_at;
    }

    /**
     * Tempo desde a submissão, por extenso.
     */
    public function waiting(CarbonImmutable $now): string
    {
        return self::elapsed($this->submittedAt(), $now);
    }

    public static function elapsed(CarbonImmutable $since, CarbonImmutable $now): string
    {
        $minutes = max(0, (int) floor($since->diffInMinutes($now)));

        if ($minutes < 60) {
            return 'menos de 1 hora';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours === 1 ? '1 hora' : "{$hours} horas";
        }

        $days = intdiv($hours, 24);

        return $days === 1 ? '1 dia' : "{$days} dias";
    }
}
