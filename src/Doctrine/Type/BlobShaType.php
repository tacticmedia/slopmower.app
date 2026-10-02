<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\BlobSha;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

#[AsDbalType(self::NAME)]
final class BlobShaType extends Type
{
    public const string NAME = 'blob_sha';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL([...$column, 'length' => 64]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?BlobSha
    {
        if (null === $value) {
            return null;
        }

        try {
            return BlobSha::fromString(is_string($value) ? $value : throw ValueNotConvertible::new($value, self::NAME));
        } catch (InvalidInput $error) {
            throw ValueNotConvertible::new($value, self::NAME, $error->getMessage(), $error);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof BlobSha => $value->toString(),
            default => throw InvalidType::new($value, self::NAME, ['null', BlobSha::class]),
        };
    }
}
