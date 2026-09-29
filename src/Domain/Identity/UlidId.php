<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Error\InvalidInput;
use Symfony\Component\Uid\Ulid;

/**
 * IDs of different types never compare equal, even with the same ULID.
 */
abstract readonly class UlidId implements \Stringable
{
    private const string PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$/D';

    final private function __construct(private Ulid $ulid)
    {
    }

    public static function generate(): static
    {
        return new static(new Ulid());
    }

    public static function fromUlid(Ulid $ulid): static
    {
        return new static($ulid);
    }

    public static function fromString(string $value, string $field = 'id'): static
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidInput::field($field, 'must be a ULID of 26 Crockford base32 characters');
        }

        return new static(new Ulid($value));
    }

    public function toUlid(): Ulid
    {
        return $this->ulid;
    }

    public function toString(): string
    {
        return $this->ulid->toBase32();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->ulid->equals($other->ulid);
    }
}
