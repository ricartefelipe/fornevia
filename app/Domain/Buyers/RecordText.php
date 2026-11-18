<?php

declare(strict_types=1);

namespace App\Domain\Buyers;

use App\Domain\Buyers\Exceptions\InvalidRecordField;

/**
 * Normalização dos campos textuais dos cadastros de compradores: espaços
 * nas extremidades removidos, vazio tratado como ausente e limites de
 * tamanho iguais aos das colunas (evita erro de banco por truncamento).
 *
 * Os detalhes das exceções trazem o nome do campo para associação da
 * mensagem ao formulário.
 */
final class RecordText
{
    public static function required(string $value, string $field, string $label, int $max): string
    {
        $text = trim($value);

        if ($text === '') {
            throw new InvalidRecordField("Informe {$label}.", ['field' => $field]);
        }

        self::assertMaxLength($text, $field, $label, $max);

        return $text;
    }

    public static function optional(?string $value, string $field, string $label, int $max): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        self::assertMaxLength($text, $field, $label, $max);

        return $text;
    }

    /**
     * Código identificador em maiúsculas: letras, números, ponto, hífen e
     * sublinhado, começando por letra ou número.
     */
    public static function code(string $value, string $field, string $label, int $max): string
    {
        $code = mb_strtoupper(self::required($value, $field, $label, $max));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]*$/D', $code) !== 1) {
            throw new InvalidRecordField(
                ucfirst(self::withoutArticle($label)).' deve conter apenas letras, números, ponto, hífen ou sublinhado.',
                ['field' => $field],
            );
        }

        return $code;
    }

    public static function email(?string $value, string $field, int $max = 254): ?string
    {
        $email = self::optional($value, $field, 'o e-mail', $max);

        if ($email === null) {
            return null;
        }

        $email = mb_strtolower($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidRecordField('Informe um e-mail válido.', ['field' => $field]);
        }

        return $email;
    }

    private static function assertMaxLength(string $text, string $field, string $label, int $max): void
    {
        if (mb_strlen($text) > $max) {
            throw new InvalidRecordField(
                ucfirst(self::withoutArticle($label))." deve ter no máximo {$max} caracteres.",
                ['field' => $field],
            );
        }
    }

    private static function withoutArticle(string $label): string
    {
        return (string) preg_replace('/^(o|a|os|as)\s+/u', '', $label);
    }
}
