<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Audit\AuditLog;
use App\Support\Observability\RedactSensitiveData;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Registro explícito de auditoria, chamado pelas Actions dentro da mesma
 * transação da alteração (nunca por observers).
 */
final readonly class AuditRecorder
{
    public function __construct(
        private CurrentContext $context,
        private Request $request,
        private RedactSensitiveData $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(string $action, ?Model $subject = null, array $data = [], ?string $reason = null): AuditLog
    {
        $execution = $this->context->require();
        $subjectId = $subject?->getKey();

        $log = new AuditLog;
        $log->forceFill([
            'actor_user_id' => $execution->userId,
            // A associação vem do contexto da transação (e não do Access), para
            // que a referência pertença sempre ao tenant em que se grava.
            'actor_membership_id' => $execution->membershipId,
            'actor_api_client_id' => $execution->apiClientId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => is_string($subjectId) ? $subjectId : null,
            'data' => $this->redactor->redact($data),
            'reason' => $reason,
            'ip' => app()->runningInConsole() ? null : $this->request->ip(),
            'correlation_id' => Context::get('correlation_id'),
        ])->save();

        return $log;
    }
}
