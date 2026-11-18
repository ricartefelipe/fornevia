<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\Permission;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Models\Audit\AuditLog;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Histórico da empresa compradora: os 50 registros de auditoria mais
 * recentes da própria empresa, dos seus cadastros (unidades, endereços,
 * centros de custo, contatos, condições) e dos acessos de usuários.
 */
final class BuyerHistory extends Component
{
    use InteractsWithBuyerCompany;

    public const int LIMIT = 50;

    /** @var array<string, string> */
    private const array ACTIONS = [
        'buyer_company.created' => 'Empresa cadastrada',
        'buyer_company.updated' => 'Dados cadastrais alterados',
        'buyer_company.status_changed' => 'Situação alterada',
        'buyer_company.payment_terms_changed' => 'Condições de pagamento alteradas',
        'buyer_unit.created' => 'Unidade cadastrada',
        'buyer_unit.updated' => 'Unidade alterada',
        'buyer_address.created' => 'Endereço cadastrado',
        'buyer_address.updated' => 'Endereço alterado',
        'cost_center.created' => 'Centro de custo cadastrado',
        'cost_center.updated' => 'Centro de custo alterado',
        'buyer_contact.created' => 'Contato cadastrado',
        'buyer_contact.updated' => 'Contato alterado',
        'buyer_contact.deleted' => 'Contato removido',
        'invitation.issued' => 'Convite enviado',
        'invitation.revoked' => 'Convite cancelado',
        'membership.granted' => 'Acesso concedido',
        'membership.revoked' => 'Acesso revogado',
        'membership.role_changed' => 'Papel alterado',
        'negotiated_price.created' => 'Condição negociada registrada',
        'negotiated_price.revoked' => 'Condição negociada revogada',
    ];

    /** @var array<string, string> */
    private const array FIELDS = [
        'legal_name' => 'razão social',
        'trade_name' => 'nome fantasia',
        'document' => 'CNPJ',
        'state_registration' => 'inscrição estadual',
        'email' => 'e-mail',
        'phone' => 'telefone',
        'notes' => 'observações',
        'customer_group_id' => 'grupo de clientes',
        'sales_rep_membership_id' => 'representante',
        'code' => 'código',
        'name' => 'nome',
        'label' => 'identificação',
        'kind' => 'tipo',
        'recipient' => 'destinatário',
        'street' => 'logradouro',
        'number' => 'número',
        'complement' => 'complemento',
        'district' => 'bairro',
        'city' => 'cidade',
        'state' => 'UF',
        'postal_code' => 'CEP',
        'buyer_unit_id' => 'unidade',
        'is_default' => 'padrão',
        'is_active' => 'situação',
        'position' => 'cargo',
        'is_primary' => 'principal',
    ];

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    #[On('buyer-updated')]
    public function refreshHistory(): void {}

    public function render(): View
    {
        $entries = $this->logs()->map(fn (AuditLog $log): array => [
            'log' => $log,
            'label' => self::ACTIONS[$log->action] ?? $log->action,
            'summary' => $this->summary($log),
        ]);

        return view('livewire.staff.buyers.buyer-history', ['entries' => $entries]);
    }

    /**
     * Resumo legível do registro (campos alterados, situações, condições).
     */
    private function summary(AuditLog $log): ?string
    {
        $data = $log->data;

        if ($log->action === 'buyer_company.status_changed') {
            return 'De '.$this->statusLabel($data['from'] ?? null).' para '.$this->statusLabel($data['to'] ?? null).'.';
        }

        if ($log->action === 'buyer_company.payment_terms_changed') {
            $after = is_array($data['after'] ?? null) ? $data['after'] : [];
            $terms = is_array($after['terms'] ?? null) ? implode(', ', array_map('strval', $after['terms'])) : '';

            return $terms === ''
                ? 'Nenhuma condição permitida.'
                : 'Permitidas: '.$terms.(is_string($after['default'] ?? null) ? ' · padrão: '.$after['default'] : '').'.';
        }

        if (str_starts_with($log->action, 'invitation.') && is_string($data['email'] ?? null)) {
            return $data['email'];
        }

        if (str_ends_with($log->action, '.updated') && is_array($data['after'] ?? null)) {
            $before = is_array($data['before'] ?? null) ? $data['before'] : [];
            $changed = array_keys(array_filter(
                $data['after'],
                static fn (mixed $value, string|int $field): bool => ! array_key_exists($field, $before) || $before[$field] !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));
            $labels = array_map(static fn (string|int $field): string => self::FIELDS[(string) $field] ?? (string) $field, $changed);

            return $labels === [] ? null : 'Campos: '.implode(', ', $labels).'.';
        }

        return null;
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function logs(): Collection
    {
        $buyerId = $this->buyerId;
        // Condições negociadas só aparecem para quem consulta preços.
        $hidden = app(Access::class)->can(Permission::PricingView) ? [] : ['negotiated_price.created', 'negotiated_price.revoked'];

        return AuditLog::query()
            ->with('actor')
            ->when($hidden !== [], static fn (Builder $query) => $query->whereNotIn('action', $hidden))
            ->where(static function (Builder $query) use ($buyerId): void {
                $query
                    ->where(static fn (Builder $q) => $q->where('subject_type', (new BuyerCompany)->getMorphClass())->where('subject_id', $buyerId))
                    // Cadastros da empresa e convites registram a empresa nos dados
                    // (coluna gerada e indexada a partir de data->>'buyer_company_id').
                    ->orWhere('buyer_company_id', $buyerId)
                    ->orWhere(static fn (Builder $q) => $q
                        ->where('subject_type', (new Membership)->getMorphClass())
                        ->whereIn('subject_id', Membership::query()->select('id')->where('buyer_company_id', $buyerId)))
                    ->orWhere(static fn (Builder $q) => $q
                        ->where('subject_type', (new Invitation)->getMorphClass())
                        ->whereIn('subject_id', Invitation::query()->select('id')->where('buyer_company_id', $buyerId)));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    private function statusLabel(mixed $value): string
    {
        $status = is_string($value) ? BuyerCompanyStatus::tryFrom($value) : null;

        return $status !== null ? '"'.$status->label().'"' : '—';
    }
}
