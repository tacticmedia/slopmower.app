<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Identity\Identifier;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Uid\Ulid;

/**
 * config/packages/doctrine.yaml registers it once per {@see Identifier} subclass, with the class name as the type name:
 * `#[ORM\Column(type: TenantId::class)]`. The column is a native uuid, as for the ulid type.
 */
final class IdentifierType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Identifier
    {
        return null === $value ? null : $this->identifierClass()::fromUlid($this->ulid($value));
    }

    /**
     * Also takes a ULID string, because {@see MapEntity} finds an entity by the route parameter.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        $class = $this->identifierClass();

        return match (true) {
            null === $value => null,
            $value instanceof $class => $value->toRfc4122(),
            default => $this->ulid($value)->toRfc4122(),
        };
    }

    /**
     * @return class-string<Identifier>
     */
    private function identifierClass(): string
    {
        $class = self::lookupName($this);

        return is_subclass_of($class, Identifier::class) ? $class : throw new \LogicException("The type name {$class} is no Identifier class.");
    }

    private function ulid(mixed $value): Ulid
    {
        $class = $this->identifierClass();

        try {
            return Ulid::fromString(\is_string($value) ? $value : throw InvalidType::new($value, $class, ['null', 'string', $class]));
        } catch (\InvalidArgumentException $error) {
            throw ValueNotConvertible::new($value, $class, $error->getMessage(), $error);
        }
    }
}
