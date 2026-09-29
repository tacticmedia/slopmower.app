<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;

/**
 * `DateTime` of common.schema.json: RFC 3339, UTC, second precision, Z suffix.
 */
final class PayloadDateTime
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';
    private const int MAX_YEAR = 9999;

    /**
     * @return ($dateTime is null ? null : string)
     *
     * @throws \LogicException for a fraction of a second, which the payload would lose,
     *                         or a UTC year outside 0000-9999, which parse() rejects
     */
    public static function format(?\DateTimeImmutable $dateTime): ?string
    {
        if (null === $dateTime) {
            return null;
        }
        if ('000000' !== $dateTime->format('u')) {
            throw new \LogicException('A payload date-time must have second precision.');
        }
        if (!self::hasPayloadYear($dateTime)) {
            throw new \LogicException('A payload date-time must have a UTC year from 0000 to 9999.');
        }

        return self::utc($dateTime)->format(self::FORMAT);
    }

    /**
     * @return list<Violation>
     */
    public static function yearViolations(?\DateTimeImmutable $dateTime, string $field): array
    {
        return null === $dateTime || self::hasPayloadYear($dateTime) ? [] : [new Violation($field, 'must have a UTC year from 0000 to 9999')];
    }

    public static function utc(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'));
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

    private static function hasPayloadYear(\DateTimeImmutable $dateTime): bool
    {
        $utcYear = (int) self::utc($dateTime)->format('Y');

        return $utcYear >= 0 && $utcYear <= self::MAX_YEAR;
    }
}
