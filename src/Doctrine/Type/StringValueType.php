<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Error\InvalidInput;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * A domain value object stored as its string form.
 *
 * @template T of \Stringable
 */
abstract class StringValueType extends Type
{
    abstract protected function name(): string;

    /**
     * @return class-string<T>
     */
    abstract protected function valueClass(): string;

    /**
     * @return T
     *
     * @throws InvalidInput
     */
    abstract protected function fromString(string $value): \Stringable;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        $valueClass = $this->valueClass();
        if (!$value instanceof $valueClass) {
            throw InvalidType::new($value, $this->name(), ['null', $valueClass]);
        }

        return (string) $value;
    }

    /**
     * @return T|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\Stringable
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw ValueNotConvertible::new($value, $this->name());
        }

        try {
            return $this->fromString($value);
        } catch (InvalidInput $error) {
            throw ValueNotConvertible::new($value, $this->name(), null, $error);
        }
    }
}
