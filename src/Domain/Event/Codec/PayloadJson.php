<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

/**
 * The stored JSON form of a payload. An empty payload is "{}", never "[]".
 */
final class PayloadJson
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        return [] === $payload ? '{}' : json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        try {
            $payload = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new CorruptPayload([['field' => '', 'message' => 'Must be valid JSON.']], $error);
        }

        if (!is_array($payload) || ([] !== $payload && array_is_list($payload))) {
            throw CorruptPayload::field('', 'Must be a JSON object.');
        }

        return $payload;
    }
}
