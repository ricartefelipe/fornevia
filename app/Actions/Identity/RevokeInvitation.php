<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\Identity\Invitation;
use App\Services\Audit\AuditRecorder;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;

final readonly class RevokeInvitation
{
    public function __construct(
        private AuthorizesMemberManagement $authorization,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(Invitation $invitation): void
    {
        $this->authorization->authorize($invitation->kind, $invitation->buyer_company_id);

        DB::transaction(function () use ($invitation): void {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->accepted_at !== null || $locked->revoked_at !== null) {
                return;
            }

            $locked->forceFill(['revoked_at' => $this->clock->now()])->save();
            $this->audit->record('invitation.revoked', $locked, ['email' => $locked->email]);
        });
    }
}
