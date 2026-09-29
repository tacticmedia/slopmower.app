<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * `datetimetz_immutable` columns keep whole seconds. A fraction would make a flushed entity differ from its row.
 */
final class WholeSeconds
{
    /**
     * @return ($dateTime is null ? null : \DateTimeImmutable)
     *
     * @throws \LogicException for a fraction of a second
     */
    public static function require(?\DateTimeImmutable $dateTime, string $field): ?\DateTimeImmutable
    {
        if (null !== $dateTime && 0 !== $dateTime->getMicrosecond()) {
            throw new \LogicException(\sprintf('%s must have whole seconds, because the database stores no fraction.', $field));
        }

        return $dateTime;
    }
}
