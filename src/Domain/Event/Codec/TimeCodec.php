<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

/**
 * RFC 3339 in UTC with "Z" and second precision, the time format of docs/specs.
 */
final class TimeCodec
{
    private const string FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Drops fractions of a second. Git commit times have none.
     *
     * @throws \LogicException For a time that decode() cannot read, for example a UTC year above 9999
     */
    public static function encode(\DateTimeImmutable $time): string
    {
        $encoded = $time->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);

        return null !== self::decode($encoded) ? $encoded : throw new \LogicException("{$encoded} is outside the years 0000 to 9999.");
    }

    public static function decode(string $value): ?\DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, $value, new \DateTimeZone('UTC'));

        return false !== $time && $time->format(self::FORMAT) === $value ? $time : null;
    }
}
