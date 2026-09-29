<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates JSON against docs/specs/schemas through their `$id`, docs/specs/README.md section 10.
 */
final class SchemaValidator
{
    public const string ID_BASE = 'https://holistic-review.invalid/schemas/v1/';

    private static ?Validator $validator = null;

    /**
     * @param string $schema a file of docs/specs/schemas, optionally with a fragment: `lists.schema.json#/$defs/RepositoryPage`
     *
     * @return array<string, list<string>> messages keyed by the JSON pointer of the failing value, empty for a valid document
     */
    public static function errors(string $json, string $schema): array
    {
        $error = self::validator()->validate(json_decode($json, flags: \JSON_THROW_ON_ERROR), self::ID_BASE.$schema)->error();
        if (null === $error) {
            return [];
        }

        /** @var array<string, list<string>> $messages */
        $messages = new ErrorFormatter()->format($error);

        return $messages;
    }

    private static function validator(): Validator
    {
        if (null === self::$validator) {
            $validator = new Validator();
            $validator->setMaxErrors(20)->setStopAtFirstError(false);
            $validator->resolver()?->registerPrefix(self::ID_BASE, \dirname(__DIR__, 3).'/docs/specs/schemas/');
            self::$validator = $validator;
        }

        return self::$validator;
    }
}
