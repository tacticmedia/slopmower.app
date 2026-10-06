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
 * config/packages/doctrine.yaml registers it once per value class, with the class name as the type name:
 * `#[ORM\Column(type: CommitSha::class)]`.
 */
final class StringValueType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match ($this->valueClass()) {
            CommitSha::class, BlobSha::class => $platform->getStringTypeDeclarationSQL([...$column, 'length' => 64]),
            RepoPath::class => $platform->getClobTypeDeclarationSQL($column),
        };
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): CommitSha|BlobSha|RepoPath|null
    {
        if (null === $value) {
            return null;
        }

        $class = $this->valueClass();

        try {
            return $class::fromString(\is_string($value) ? $value : throw ValueNotConvertible::new($value, $class));
        } catch (InvalidInput $error) {
            throw ValueNotConvertible::new($value, $class, $error->getMessage(), $error);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        $class = $this->valueClass();

        return match (true) {
            null === $value => null,
            $value instanceof $class => $value->toString(),
            default => throw InvalidType::new($value, $class, ['null', $class]),
        };
    }

    /**
     * @return class-string<CommitSha|BlobSha|RepoPath>
     */
    private function valueClass(): string
    {
        return match ($class = self::lookupName($this)) {
            CommitSha::class, BlobSha::class, RepoPath::class => $class,
            default => throw new \LogicException("The type name {$class} is no string value class."),
        };
    }
}
