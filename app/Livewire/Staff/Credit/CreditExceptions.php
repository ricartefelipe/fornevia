<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Credit;

use App\Actions\Credit\CreditAccountRecords;
use App\Actions\Credit\GrantCreditException;
use App\Actions\Credit\RevokeCreditException;
use App\Domain\Buyers\Justification;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Credit\Concerns\InteractsWithCreditAccount;
use App\Models\Credit\CreditException;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Exceções temporárias de limite da conta: concessão (valor, vigência e
 * justificativa) e revogação com justificativa. Somente exceções vigentes
 * somam ao disponível; agendadas, encerradas e revogadas ficam no histórico.
 */
final class CreditExceptions extends Component
{
    use InteractsWithCreditAccount;
    use WithPagination;

    public const string PAGE_NAME = 'excecoes';

    public string $amount = '';

    public string $firstDay = '';

    public string $lastDay = '';

    public string $reason = '';

    public string $revokeReason = '';

    public ?string $message = null;

    public function mount(string $buyerId, Clock $clock): void
    {
        $this->bindBuyer($buyerId);
        $this->firstDay = $clock->today()->toDateString();
    }

    #[On(self::UPDATED_EVENT)]
    public function refreshExceptions(): void {}

    public function grant(GrantCreditException $grant, Clock $clock): void
    {
        $this->validate([
            'amount' => ['required', 'string', 'max:25'],
            'firstDay' => ['required', ...BusinessDate::rules()],
            'lastDay' => ['required', ...BusinessDate::rules(), 'after_or_equal:firstDay'],
            'reason' => ['required', 'string', 'min:'.Justification::MIN_LENGTH, 'max:'.Justification::MAX_LENGTH],
        ], [], [
            'amount' => 'valor da exceção',
            'firstDay' => 'primeiro dia',
            'lastDay' => 'último dia',
            'reason' => 'justificativa',
        ]);

        $amount = $this->moneyOrError('amount', $this->amount);

        if ($amount === null) {
            return;
        }

        try {
            $exception = $grant->handle(
                $this->company(),
                $amount,
                CarbonImmutable::parse($this->firstDay),
                CarbonImmutable::parse($this->lastDay),
                $this->reason,
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['amount' => 'amount', 'first_day' => 'firstDay', 'last_day' => 'lastDay', 'reason' => 'reason'], 'amount');

            return;
        }

        $this->reset('amount', 'lastDay', 'reason');
        $this->firstDay = $clock->today()->toDateString();
        $this->resetErrorBag();
        $this->resetPage(self::PAGE_NAME);
        $this->message = "Exceção de {$exception->amount_cents->format()} concedida.";
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function revoke(string $exceptionId, RevokeCreditException $revoke): void
    {
        $exception = CreditException::query()
            ->where('buyer_company_id', $this->buyerId)
            ->findOrFail(UntrustedId::uuid($exceptionId, CreditException::class));

        try {
            $revoke->handle($exception, $this->revokeReason);
        } catch (BusinessRuleViolation $e) {
            $this->addError('revokeReason', $e->getMessage());

            return;
        }

        $this->reset('revokeReason');
        $this->resetErrorBag('revokeReason');
        $this->message = "Exceção de {$exception->amount_cents->format()} revogada.";
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(CreditAccountRecords $records): View
    {
        $account = $this->account();

        return view('livewire.staff.credit.credit-exceptions', [
            'exceptions' => $account !== null ? $records->exceptions($account, 10, self::PAGE_NAME) : null,
            'canManage' => $this->canManage(),
            'maxDays' => GrantCreditException::MAX_DAYS,
        ]);
    }
}
