<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates JSON against docs/specs/schemas, which the `$id` prefix maps to (docs/specs/README.md section 10).
 */
final class SchemaValidator
{
    private const string ID_PREFIX = 'https://holistic-review.invalid/schemas/v1/';

    private static ?Validator $validator = null;

    /**
     * @param string $schema A schema file, with an optional JSON pointer: "lists.schema.json#/$defs/RepositoryPage"
     *
     * @return list<string> One line per error, empty for valid JSON
     */
    public static function errors(string $json, string $schema): array
    {
        $result = self::validator()->validate(json_decode($json, flags: \JSON_THROW_ON_ERROR), self::ID_PREFIX.$schema);
        $error = $result->error();

        if (null === $error) {
            return [];
        }

        $lines = [];

        foreach (new ErrorFormatter()->format($error, false) as $pointer => $message) {
            $lines[] = "{$pointer}: ".(\is_string($message) ? $message : json_encode($message, \JSON_THROW_ON_ERROR));
        }

        return $lines;
    }

    private static function validator(): Validator
    {
        if (null === self::$validator) {
            self::$validator = new Validator();
            self::$validator->setMaxErrors(10);
            self::$validator->resolver()?->registerPrefix(self::ID_PREFIX, dirname(__DIR__, 3).'/docs/specs/schemas/');
        }

        return self::$validator;
    }
}
