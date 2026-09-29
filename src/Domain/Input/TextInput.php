<?php

declare(strict_types=1);

namespace App\Domain\Input;

use App\Domain\Error\Violation;

/**
 * Text limits of docs/specs/README.md section 2.2, in characters as JSON Schema counts them.
 * Every text lands in a jsonb payload, which cannot hold U+0000.
 */
final class TextInput
{
    public const int MAX_CHARACTERS = 65536;

    /**
     * `Markdown` of common.schema.json: a body or a required note.
     *
     * @return list<Violation>
     */
    public static function markdown(string $value, string $field): array
    {
        return self::violations($value, $field, minCharacters: 1, minTrimmedCharacters: 1);
    }

    /**
     * An optional note: null, or 0 to 65,536 characters.
     *
     * @return list<Violation>
     */
    public static function optionalNote(?string $value, string $field): array
    {
        return null === $value ? [] : self::violations($value, $field);
    }

    /**
     * @return list<Violation> one for the first rule that fails, else none
     */
    public static function violations(
        string $value,
        string $field,
        int $minCharacters = 0,
        int $maxCharacters = self::MAX_CHARACTERS,
        int $minTrimmedCharacters = 0,
    ): array {
        $problem = match (true) {
            !mb_check_encoding($value, 'UTF-8') => 'must be valid UTF-8',
            str_contains($value, "\0") => 'must not contain U+0000',
            mb_strlen($value, 'UTF-8') < $minCharacters, mb_strlen($value, 'UTF-8') > $maxCharacters => \sprintf('must have %d to %d characters', $minCharacters, $maxCharacters),
            mb_strlen(mb_trim($value, encoding: 'UTF-8'), 'UTF-8') >= $minTrimmedCharacters => null,
            1 === $minTrimmedCharacters => 'must contain a non-whitespace character',
            default => \sprintf('must have %d or more characters after trimming', $minTrimmedCharacters),
        };

        return null === $problem ? [] : [new Violation($field, $problem)];
    }
}
