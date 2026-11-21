<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Data\Pricing\PriceListData;
use App\Domain\Pricing\Exceptions\InvalidPriceList;
use App\Domain\Pricing\Exceptions\InvalidPriceListTarget;
use App\Domain\Pricing\Exceptions\InvalidValidityPeriod;
use App\Domain\Pricing\Exceptions\PriceListCodeTaken;
use App\Domain\Pricing\Exceptions\PriceListNotEditable;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Pricing\PriceList;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria ou altera o cabeçalho de uma tabela de preço.
 *
 * Tabelas novas nascem como rascunho; a situação só muda por
 * ActivatePriceList e ArchivePriceList. Grupo e empresa são recarregados no
 * escopo do tenant (identificadores de outro tenant equivalem a
 * inexistentes). Toda gravação incrementa a versão de preços.
 */
final readonly class SavePriceList
{
    public const int MAX_PRIORITY = 9_999;

    private const string CODE_PATTERN = '/^[A-Z0-9][A-Z0-9._-]{0,29}$/D';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $version,
    ) {}

    public function handle(?PriceList $priceList, PriceListData $data): PriceList
    {
        $this->access->authorize(Permission::PricingManage);

        $code = mb_strtoupper(trim($data->code));
        $name = trim($data->name);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidPriceList(
                'Informe um código de até 30 caracteres: letras, números, ponto, hífen ou sublinhado.',
                ['field' => 'code'],
            );
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidPriceList('Informe um nome de até 120 caracteres.', ['field' => 'name']);
        }

        if (abs($data->priority) > self::MAX_PRIORITY) {
            throw new InvalidPriceList('A prioridade deve estar entre -9.999 e 9.999.', ['field' => 'priority']);
        }

        if ($data->validUntil !== null && $data->validUntil->toDateString() < $data->validFrom->toDateString()) {
            throw new InvalidValidityPeriod('O término da vigência não pode ser anterior ao início.');
        }

        try {
            return DB::transaction(function () use ($priceList, $data, $code, $name): PriceList {
                $list = $priceList === null
                    ? new PriceList
                    : PriceList::query()->lockForUpdate()->findOrFail($priceList->id);

                if ($list->exists && $list->status === PriceListStatus::Archived) {
                    throw new PriceListNotEditable('Tabela arquivada não pode ser alterada.');
                }

                [$groupId, $companyId] = $this->target($data);
                $this->assertCodeAvailable($code, $list->exists ? $list->id : null);

                $creating = ! $list->exists;
                $before = $creating ? null : self::snapshot($list);

                $list->fill(['code' => $code, 'name' => $name]);
                $list->forceFill([
                    'scope' => $data->scope,
                    'customer_group_id' => $groupId,
                    'buyer_company_id' => $companyId,
                    'priority' => $data->priority,
                    'valid_from' => $data->validFrom->toDateString(),
                    'valid_until' => $data->validUntil?->toDateString(),
                ]);

                if ($creating) {
                    $list->forceFill(['status' => PriceListStatus::Draft]);
                }

                $list->save();

                $this->audit->record($creating ? 'price_list.created' : 'price_list.updated', $list, [
                    'before' => $before,
                    'after' => self::snapshot($list),
                ]);
                $this->version->bump();

                return $list;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PriceListCodeTaken("Já existe uma tabela de preço com o código {$code}.", ['field' => 'code']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(PriceList $list): array
    {
        return [
            'code' => $list->code,
            'name' => $list->name,
            'scope' => $list->scope->value,
            'customer_group_id' => $list->customer_group_id,
            'buyer_company_id' => $list->buyer_company_id,
            'priority' => $list->priority,
            'valid_from' => $list->valid_from->toDateString(),
            'valid_until' => $list->valid_until?->toDateString(),
            'status' => $list->status->value,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function target(PriceListData $data): array
    {
        return match ($data->scope) {
            PriceListScope::Default => $data->customerGroupId === null && $data->buyerCompanyId === null
                ? [null, null]
                : throw new InvalidPriceListTarget('A tabela padrão vale para todas as empresas e não tem grupo nem empresa.', ['field' => 'scope']),
            PriceListScope::Group => $data->buyerCompanyId === null && self::exists(CustomerGroup::class, $data->customerGroupId)
                ? [$data->customerGroupId, null]
                : throw new InvalidPriceListTarget('Selecione um grupo de clientes cadastrado.', ['field' => 'customer_group_id']),
            PriceListScope::Customer => $data->customerGroupId === null && self::exists(BuyerCompany::class, $data->buyerCompanyId)
                ? [null, $data->buyerCompanyId]
                : throw new InvalidPriceListTarget('Selecione uma empresa compradora cadastrada.', ['field' => 'buyer_company_id']),
        };
    }

    /**
     * @param  class-string<CustomerGroup|BuyerCompany>  $model
     */
    private static function exists(string $model, ?string $id): bool
    {
        return $id !== null && Str::isUuid($id) && $model::query()->whereKey($id)->exists();
    }

    private function assertCodeAvailable(string $code, ?string $ignoreId): void
    {
        $taken = PriceList::query()
            ->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw new PriceListCodeTaken("Já existe uma tabela de preço com o código {$code}.", ['field' => 'code']);
        }
    }
}
