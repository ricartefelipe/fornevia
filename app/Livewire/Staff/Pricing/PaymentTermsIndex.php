<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing;

use App\Actions\Pricing\SavePaymentTerm;
use App\Data\Pricing\PaymentTermData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Livewire\Staff\Pricing\Concerns\InteractsWithPricingForms;
use App\Models\Pricing\PaymentTerm;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Condições de pagamento do distribuidor (à vista ou a prazo, com dias de
 * vencimento por parcela).
 */
#[Layout('layouts.app')]
#[Title('Condições de pagamento')]
final class PaymentTermsIndex extends Component
{
    use InteractsWithPricingForms;
    use WithPagination;

    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $installmentDays = '0';

    public bool $requiresCredit = false;

    public bool $isActive = true;

    public ?string $message = null;

    public function edit(string $paymentTermId): void
    {
        $term = PaymentTerm::query()->findOrFail(UntrustedId::uuid($paymentTermId, PaymentTerm::class));

        $this->resetErrorBag();
        $this->editingId = $term->id;
        $this->code = $term->code;
        $this->name = $term->name;
        $this->installmentDays = implode('/', $term->installment_days);
        $this->requiresCredit = $term->requires_credit;
        $this->isActive = $term->is_active;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'code', 'name', 'installmentDays', 'requiresCredit', 'isActive');
        $this->resetErrorBag();
    }

    public function save(SavePaymentTerm $save): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:80'],
            'installmentDays' => ['required', 'string', 'max:60'],
            'requiresCredit' => ['boolean'],
            'isActive' => ['boolean'],
        ], [
            'code.regex' => 'Use letras, números, ponto, hífen ou sublinhado, começando por letra ou número.',
        ], [
            'code' => 'código',
            'name' => 'nome',
            'installmentDays' => 'dias de vencimento',
        ]);

        $days = self::parseDays($this->installmentDays);

        if ($days === null) {
            $this->addError('installmentDays', 'Informe os dias de vencimento separados por barra, por exemplo 30/60/90 (0 para à vista).');

            return;
        }

        $wasEditing = $this->editingId !== null;

        try {
            $term = $save->handle(
                $wasEditing ? PaymentTerm::query()->findOrFail($this->editingId) : null,
                new PaymentTermData($this->code, $this->name, $days, $this->requiresCredit, $this->isActive),
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['code' => 'code', 'name' => 'name', 'installment_days' => 'installmentDays'], 'code');

            return;
        }

        $this->cancel();
        $this->message = $wasEditing ? "Condição {$term->code} atualizada." : "Condição {$term->code} cadastrada.";
    }

    public function render(Access $access): View
    {
        return view('livewire.staff.pricing.payment-terms-index', [
            'terms' => PaymentTerm::query()->orderByDesc('is_active')->orderBy('code')->paginate(25),
            'canManage' => $access->can(Permission::PricingManage),
        ]);
    }

    /**
     * "30/60/90", "30, 60, 90" ou "0" => lista de inteiros; null quando o
     * formato não é reconhecido. Faixas e ordem são validadas pela Action.
     *
     * @return list<int>|null
     */
    private static function parseDays(string $value): ?array
    {
        $parts = preg_split('/[\s,;\/]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return null;
        }

        $days = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d{1,3}$/D', $part) !== 1) {
                return null;
            }

            $days[] = (int) $part;
        }

        return $days;
    }
}
