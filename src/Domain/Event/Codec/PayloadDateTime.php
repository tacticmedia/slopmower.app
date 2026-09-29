<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Error\InvalidInput;

/**
 * `DateTime` of common.schema.json: RFC 3339, UTC, second precision, Z suffix.
 */
final class PayloadDateTime
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @return ($dateTime is null ? null : string)
     *
     * @throws \LogicException for a fraction of a second, which the payload would lose
     */
    public static function format(?\DateTimeImmutable $dateTime): ?string
    {
        if (null === $dateTime) {
            return null;
        }
        if ('000000' !== $dateTime->format('u')) {
            throw new \LogicException('A payload date-time must have second precision.');
        }

        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function parse(string $value, string $field): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, $value, new \DateTimeZone('UTC'));
        // The comparison rejects the overflow that createFromFormat() accepts, such as February 30.
        if (false === $dateTime || $dateTime->format(self::FORMAT) !== $value) {
            throw InvalidInput::field($field, 'must be an RFC 3339 UTC date-time with second precision and a Z suffix');
        }

        return $dateTime;
    }
}
