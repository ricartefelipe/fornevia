<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Remove valores sensíveis do contexto dos logs antes da serialização.
 * A comparação considera o nome da chave, em qualquer nível de aninhamento.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    private const string MASK = '[redacted]';

    /** @var list<string> Chaves curtas comparadas por igualdade. */
    private const array SENSITIVE_KEYS = ['pan', 'cvv', 'cvc', 'cpf', 'ccv'];

    /** @var list<string> Fragmentos procurados no nome da chave. */
    private const array SENSITIVE_FRAGMENTS = [
        'password', 'senha', 'secret', 'token', 'authorization', 'cookie',
        'api_key', 'apikey', 'access_key', 'card_number', 'creditcard',
        'credit_card', 'two_factor', 'recovery_code', 'signature', 'app_key',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
