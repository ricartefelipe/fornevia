<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Data\Buyers\BuyerContactData;
use App\Domain\Buyers\RecordText;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerContact;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera um contato da empresa compradora. Há no máximo um
 * contato principal por empresa: ao marcar um contato como principal, o
 * anterior é desmarcado na mesma transação, sob bloqueio da empresa.
 */
final readonly class SaveBuyerContact
{
    /** @var list<string> */
    private const array AUDITED = ['name', 'email', 'phone', 'position', 'is_primary'];

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private BuyerRecords $records,
    ) {}

    public function handle(BuyerCompany $company, ?BuyerContact $contact, BuyerContactData $data): BuyerContact
    {
        $this->access->authorize(Permission::BuyersManage);

        $attributes = [
            'name' => RecordText::required($data->name, 'name', 'o nome', 160),
            'email' => RecordText::email($data->email, 'email'),
            'phone' => RecordText::optional($data->phone, 'phone', 'o telefone', 20),
            'position' => RecordText::optional($data->position, 'position', 'o cargo', 80),
            'is_primary' => $data->isPrimary,
        ];

        return DB::transaction(function () use ($company, $contact, $attributes): BuyerContact {
            $company = $this->records->lockCompany($company);

            if ($contact !== null) {
                $contact = BuyerContact::query()
                    ->where('buyer_company_id', $company->id)
                    ->lockForUpdate()
                    ->findOrFail($contact->id);
            } else {
                $contact = new BuyerContact;
                $contact->forceFill(['buyer_company_id' => $company->id]);
            }

            $creating = ! $contact->exists;
            $before = $creating ? null : $contact->only(self::AUDITED);

            $replacedPrimaryIds = [];

            if ($attributes['is_primary']) {
                $previous = BuyerContact::query()
                    ->where('buyer_company_id', $company->id)
                    ->where('is_primary', true)
                    ->when(! $creating, static fn ($query) => $query->whereKeyNot($contact->id))
                    ->lockForUpdate()
                    ->get();

                foreach ($previous as $other) {
                    $other->forceFill(['is_primary' => false])->save();
                    $replacedPrimaryIds[] = $other->id;
                }
            }

            $contact->fill($attributes);

            if (! $creating && ! $contact->isDirty()) {
                return $contact;
            }

            $contact->save();

            $this->audit->record($creating ? 'buyer_contact.created' : 'buyer_contact.updated', $contact, [
                'buyer_company_id' => $company->id,
                'before' => $before,
                'after' => $contact->only(self::AUDITED),
                'replaced_primary_ids' => $replacedPrimaryIds,
            ]);

            return $contact;
        });
    }
}
