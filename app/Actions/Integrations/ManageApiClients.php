<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Enums\Identity\Permission;
use App\Models\Integrations\ApiClient;
use App\Models\Integrations\ApiToken;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Integrations\ApiAbilities;
use Illuminate\Support\Facades\DB;

/**
 * Clientes de API do distribuidor (RF-110), por quem tem
 * integrations.manage: criação com permissões da lista permitida e um
 * token com prazo (texto mostrado uma única vez), novo token e revogação
 * (do cliente inteiro ou de um token).
 */
final readonly class ManageApiClients
{
    public const int MAX_DAYS = 365;

    public function __construct(private Access $access, private AuditRecorder $audit, private Clock $clock) {}

    /**
     * @param  list<string>  $permissions
     * @return array{0: ApiClient, 1: string} cliente e token em texto simples
     */
    public function create(string $name, array $permissions, int $validDays): array
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $name = trim($name);
        $granted = ApiAbilities::filter($permissions);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidIntegration('Informe um nome de até 80 caracteres.', ['field' => 'name']);
        }

        if ($granted === [] || count($granted) !== count(array_unique($permissions))) {
            throw new InvalidIntegration('Escolha ao menos uma permissão da lista.', ['field' => 'permissions']);
        }

        return DB::transaction(function () use ($name, $granted, $validDays): array {
            $client = new ApiClient;
            $client->forceFill(['name' => $name, 'permissions' => $granted, 'created_by_user_id' => $this->access->userId()])->save();
            $plain = $this->token($client, $validDays);
            $this->audit->record('api_client.created', $client, ['permissions' => $granted]);

            return [$client, $plain];
        });
    }

    public function newToken(mixed $clientId, int $validDays): string
    {
        $this->access->authorize(Permission::IntegrationsManage);

        return DB::transaction(function () use ($clientId, $validDays): string {
            $client = ApiClient::query()->whereNull('revoked_at')->lockForUpdate()->findOrFail(UntrustedId::uuid($clientId, ApiClient::class));
            $plain = $this->token($client, $validDays);
            $this->audit->record('api_client.token_issued', $client);

            return $plain;
        });
    }

    public function revokeToken(int $tokenId): void
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $token = ApiToken::query()->whereKey($tokenId)->where('tokenable_type', 'api_client')->firstOrFail();
        $token->delete();
        $this->audit->record('api_client.token_revoked', ApiClient::query()->find($token->tokenable_id), ['token_id' => $tokenId]);
    }

    public function revoke(mixed $clientId): void
    {
        $this->access->authorize(Permission::IntegrationsManage);

        DB::transaction(function () use ($clientId): void {
            $client = ApiClient::query()->whereNull('revoked_at')->lockForUpdate()->findOrFail(UntrustedId::uuid($clientId, ApiClient::class));
            $client->forceFill(['revoked_at' => $this->clock->now()])->save();
            ApiToken::query()->where('tokenable_type', 'api_client')->where('tokenable_id', $client->id)->delete();
            $this->audit->record('api_client.revoked', $client);
        });
    }

    private function token(ApiClient $client, int $validDays): string
    {
        if ($validDays < 1 || $validDays > self::MAX_DAYS) {
            throw new InvalidIntegration('O prazo do token vai de 1 a 365 dias.', ['field' => 'valid_days']);
        }

        [, $plain] = $client->issueToken('Token de '.$client->name, ['*'], $this->clock->now()->addDays($validDays));

        return $plain;
    }
}
