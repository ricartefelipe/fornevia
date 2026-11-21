<?php

declare(strict_types=1);

namespace App\Data\Credit;

use App\Domain\Credit\CreditPosition;
use App\Domain\Shared\Money;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Credit\CreditAccountStatus;

/**
 * Linha da lista de crédito: empresa compradora e, quando houver conta,
 * os valores da posição. Exposição e disponível vêm de CreditPosition (a
 * mesma fórmula da confirmação de pedidos, RN-080).
 *
 * A lista não avalia títulos vencidos acima da tolerância (consulta por
 * empresa feita pelo CreditLedger); a decisão completa de compra a prazo
 * aparece no detalhe da conta.
 */
final readonly class CreditAccountSummary
{
    public function __construct(
        public string $buyerCompanyId,
        public string $tradeName,
        public string $legalName,
        public string $document,
        public BuyerCompanyStatus $companyStatus,
        public ?string $accountId,
        public ?CreditAccountStatus $status,
        public ?CreditPosition $position,
    ) {}

    /**
     * @param  array{limit: int, exceptions: int, committed: int, receivable: int}|null  $cents
     */
    public static function make(
        string $buyerCompanyId,
        string $tradeName,
        string $legalName,
        string $document,
        BuyerCompanyStatus $companyStatus,
        ?string $accountId,
        ?CreditAccountStatus $status,
        ?array $cents,
    ): self {
        $position = $accountId !== null && $status !== null && $cents !== null
            ? new CreditPosition(
                Money::ofCents($cents['limit']),
                Money::ofCents($cents['exceptions']),
                Money::ofCents($cents['committed']),
                Money::ofCents($cents['receivable']),
                $status === CreditAccountStatus::Blocked,
                false,
            )
            : null;

        return new self($buyerCompanyId, $tradeName, $legalName, $document, $companyStatus, $accountId, $status, $position);
    }

    public function hasAccount(): bool
    {
        return $this->accountId !== null;
    }

    public function situationLabel(): string
    {
        return $this->status?->label() ?? 'Sem conta';
    }

    public function situationColor(): string
    {
        return match ($this->status) {
            CreditAccountStatus::Active => 'green',
            CreditAccountStatus::Blocked => 'red',
            null => 'slate',
        };
    }
}
