<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Error\InvalidInput;
use Symfony\Component\Uid\Ulid;

abstract readonly class Identifier implements \Stringable
{
    final private function __construct(private Ulid $ulid)
    {
    }

    public static function generate(): static
    {
        return new static(new Ulid());
    }

    /**
     * @param string|null $field Defaults to the class name in camel case, for example "tenantId"
     */
    public static function fromString(string $value, ?string $field = null): static
    {
        if (!Ulid::isValid($value)) {
            throw InvalidInput::field($field ?? lcfirst(new \ReflectionClass(static::class)->getShortName()), 'Must be a ULID of 26 Crockford base32 characters.');
        }

        return new static(new Ulid($value));
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $this->ulid->equals($other->ulid);
    }

    public function toString(): string
    {
        return $this->ulid->toBase32();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
