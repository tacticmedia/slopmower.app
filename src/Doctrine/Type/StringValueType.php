<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * config/packages/doctrine_types.php registers it once per value class, with the class name as the type name:
 * `#[ORM\Column(type: CommitSha::class)]`.
 */
final class StringValueType extends Type
{
    /** @var class-string<CommitSha|BlobSha|RepoPath> */
    private readonly string $valueClass;

    public function __construct(string $valueClass)
    {
        $this->valueClass = match ($valueClass) {
            CommitSha::class, BlobSha::class, RepoPath::class => $valueClass,
            default => throw new \LogicException("{$valueClass} is no string value class."),
        };
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match ($this->valueClass) {
            CommitSha::class, BlobSha::class => $platform->getStringTypeDeclarationSQL([...$column, 'length' => 64]),
            RepoPath::class => $platform->getClobTypeDeclarationSQL($column),
        };
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): CommitSha|BlobSha|RepoPath|null
    {
        if (null === $value) {
            return null;
        }

        try {
            return $this->valueClass::fromString(\is_string($value) ? $value : throw ValueNotConvertible::new($value, $this->valueClass));
        } catch (InvalidInput $error) {
            throw ValueNotConvertible::new($value, $this->valueClass, $error->getMessage(), $error);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof $this->valueClass => $value->toString(),
            default => throw InvalidType::new($value, $this->valueClass, ['null', $this->valueClass]),
        };
    }
}
