<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Credit;

use App\Actions\Credit\BlockCreditAccount;
use App\Actions\Credit\ChangeCreditLimit;
use App\Actions\Credit\ChangeOverdueTolerance;
use App\Actions\Credit\CreditAccounts;
use App\Actions\Credit\OpenCreditAccount;
use App\Actions\Credit\UnblockCreditAccount;
use App\Domain\Buyers\Justification;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Identity\Permission;
use App\Livewire\Staff\Credit\Concerns\InteractsWithCreditAccount;
use App\Models\Buyers\BuyerCompany;
use App\Services\Credit\CreditLedger;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Conta de crédito comercial da empresa compradora: posição calculada pelo
 * CreditLedger (a mesma usada na confirmação de pedidos a prazo), abertura
 * da conta, alteração de limite, bloqueio e desbloqueio, tolerância de
 * vencidos. Exceções temporárias e históricos são seções (componentes
 * filhos) que recebem apenas o identificador da empresa.
 */
#[Layout('layouts.app')]
#[Title('Conta de crédito')]
final class CreditAccountShow extends Component
{
    use InteractsWithCreditAccount;

    public string $openLimit = '';

    public string $openTolerance = '0';

    public string $openReason = '';

    public string $newLimit = '';

    public string $limitReason = '';

    public string $statusReason = '';

    public string $tolerance = '';

    public string $toleranceReason = '';

    public ?string $message = null;

    public function mount(BuyerCompany $buyer): void
    {
        $this->buyerId = $buyer->id;
        $this->fillFromAccount();
    }

    #[On(self::UPDATED_EVENT)]
    public function refreshPosition(): void {}

    public function open(OpenCreditAccount $open): void
    {
        $this->validate([
            'openLimit' => ['required', 'string', 'max:25'],
            'openTolerance' => ['required', 'integer', 'between:0,'.CreditAccounts::MAX_TOLERANCE_DAYS],
            'openReason' => self::reasonRules(),
        ], [], ['openLimit' => 'limite inicial', 'openTolerance' => 'tolerância de vencidos', 'openReason' => 'justificativa']);

        $limit = $this->moneyOrError('openLimit', $this->openLimit);

        if ($limit === null) {
            return;
        }

        try {
            $account = $open->handle($this->company(), $limit, (int) $this->openTolerance, $this->openReason);
        } catch (BusinessRuleViolation $e) {
            // Sem campo associado (conta aberta em outra aba, por exemplo): aviso no topo da página.
            $this->addViolation($e, ['limit' => 'openLimit', 'overdue_tolerance_days' => 'openTolerance', 'reason' => 'openReason'], 'account');

            return;
        }

        $this->reset('openLimit', 'openTolerance', 'openReason');
        $this->resetErrorBag();
        $this->fillFromAccount();
        $this->message = "Conta de crédito aberta com limite de {$account->limit_cents->format()}.";
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function changeLimit(ChangeCreditLimit $change, CreditLedger $ledger): void
    {
        $this->validate([
            'newLimit' => ['required', 'string', 'max:25'],
            'limitReason' => self::reasonRules(),
        ], [], ['newLimit' => 'novo limite', 'limitReason' => 'justificativa']);

        $limit = $this->moneyOrError('newLimit', $this->newLimit);

        if ($limit === null) {
            return;
        }

        try {
            $account = $change->handle($this->company(), $limit, $this->limitReason);
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['limit' => 'newLimit', 'reason' => 'limitReason'], 'newLimit');

            return;
        }

        $this->reset('limitReason');
        $this->resetErrorBag();
        $this->fillFromAccount();
        $available = $ledger->positionFor($this->buyerId)->available();
        $this->message = "Limite alterado para {$account->limit_cents->format()}."
            .($available->isNegative() ? ' A exposição atual supera o novo limite: novos pedidos a prazo serão recusados até haver saldo disponível.' : '');
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function block(BlockCreditAccount $block): void
    {
        $this->validate(['statusReason' => self::reasonRules()], [], ['statusReason' => 'motivo do bloqueio']);

        try {
            $block->handle($this->company(), $this->statusReason);
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, [], 'statusReason');

            return;
        }

        $this->reset('statusReason');
        $this->resetErrorBag();
        $this->message = 'Crédito bloqueado. Novos pedidos a prazo desta empresa serão recusados.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function unblock(UnblockCreditAccount $unblock): void
    {
        $this->validate(['statusReason' => self::reasonRules()], [], ['statusReason' => 'justificativa do desbloqueio']);

        try {
            $unblock->handle($this->company(), $this->statusReason);
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, [], 'statusReason');

            return;
        }

        $this->reset('statusReason');
        $this->resetErrorBag();
        $this->message = 'Crédito desbloqueado. Pedidos a prazo voltam a ser aceitos dentro do limite disponível.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function changeTolerance(ChangeOverdueTolerance $change): void
    {
        $this->validate([
            'tolerance' => ['required', 'integer', 'between:0,'.CreditAccounts::MAX_TOLERANCE_DAYS],
            'toleranceReason' => self::reasonRules(),
        ], [], ['tolerance' => 'tolerância de vencidos', 'toleranceReason' => 'justificativa']);

        try {
            $account = $change->handle($this->company(), (int) $this->tolerance, $this->toleranceReason);
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['overdue_tolerance_days' => 'tolerance', 'reason' => 'toleranceReason'], 'tolerance');

            return;
        }

        $this->reset('toleranceReason');
        $this->resetErrorBag();
        $this->message = "Tolerância de vencidos alterada para {$account->overdue_tolerance_days} dia(s).";
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(CreditLedger $ledger, Access $access): View
    {
        $company = $this->company();
        $account = $this->account();
        $position = $account !== null ? $ledger->positionFor($company->id) : null;

        return view('livewire.staff.credit.credit-account-show', [
            'company' => $company,
            'account' => $account,
            'position' => $position,
            // Decisão para um novo pedido a prazo de qualquer valor positivo.
            'decision' => $position?->decide(Money::ofCents(1)),
            'blocked' => $account?->status === CreditAccountStatus::Blocked,
            'canManage' => $access->can(Permission::CreditManage),
        ]);
    }

    /**
     * Pré-preenche os formulários com os valores atuais da conta.
     */
    private function fillFromAccount(): void
    {
        $account = $this->account();

        if ($account === null) {
            return;
        }

        $this->newLimit = self::moneyInput($account->limit_cents);
        $this->tolerance = (string) $account->overdue_tolerance_days;
    }

    /**
     * @return list<string>
     */
    private static function reasonRules(): array
    {
        return ['required', 'string', 'min:'.Justification::MIN_LENGTH, 'max:'.Justification::MAX_LENGTH];
    }
}
