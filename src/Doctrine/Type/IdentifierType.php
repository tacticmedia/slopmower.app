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
 * config/packages/doctrine_types.php registers it once per {@see Identifier} subclass, with the class name as the type name:
 * `#[ORM\Column(type: TenantId::class)]`. The column is a native uuid, as for the ulid type.
 */
final class IdentifierType extends Type
{
    /** @var class-string<Identifier> */
    private readonly string $identifierClass;

    public function __construct(string $identifierClass)
    {
        $this->identifierClass = is_subclass_of($identifierClass, Identifier::class) ? $identifierClass : throw new \LogicException("{$identifierClass} is no Identifier class.");
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Identifier
    {
        return null === $value ? null : $this->identifierClass::fromUlid($this->ulid($value));
    }

    /**
     * Also takes a ULID string, because {@see MapEntity} finds an entity by the route parameter.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof $this->identifierClass => $value->toRfc4122(),
            default => $this->ulid($value)->toRfc4122(),
        };
    }

    private function ulid(mixed $value): Ulid
    {
        try {
            return Ulid::fromString(\is_string($value) ? $value : throw InvalidType::new($value, $this->identifierClass, ['null', 'string', $this->identifierClass]));
        } catch (\InvalidArgumentException $error) {
            throw ValueNotConvertible::new($value, $this->identifierClass, $error->getMessage(), $error);
        }
    }
}
