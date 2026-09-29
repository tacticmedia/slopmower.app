<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Error\InvalidInput;

/**
 * The stored text of `review_event.payload`: compact, the top level always an object.
 */
final class PayloadJson
{
    private const int ENCODE_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;
    private const string JSON_WHITESPACE = " \t\n\r";

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \JsonException for a value that JSON cannot hold, such as invalid UTF-8
     */
    public static function encode(array $payload): string
    {
        return json_encode((object) $payload, self::ENCODE_FLAGS);
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $json, string $field = 'payload'): array
    {
        if (!str_starts_with(ltrim($json, self::JSON_WHITESPACE), '{')) {
            throw InvalidInput::field($field, 'must be a JSON object');
        }
        try {
            $payload = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidInput::field($field, 'must be a JSON object');
        }

        return \is_array($payload) ? $payload : throw InvalidInput::field($field, 'must be a JSON object');
    }
}
