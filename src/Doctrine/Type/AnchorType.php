<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Anchor\Anchor;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\CorruptPayload;
use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\Codec\PayloadReader;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

#[AsDbalType(self::NAME)]
final class AnchorType extends Type
{
    public const string NAME = 'anchor';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonbTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Anchor
    {
        if (null === $value) {
            return null;
        }

        try {
            return AnchorCodec::decode(PayloadReader::of(PayloadJson::decode(is_string($value) ? $value : throw ValueNotConvertible::new($value, self::NAME))));
        } catch (CorruptPayload $error) {
            throw ValueNotConvertible::new($value, self::NAME, $error->getMessage(), $error);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof Anchor => PayloadJson::encode(AnchorCodec::encode($value)),
            default => throw InvalidType::new($value, self::NAME, ['null', Anchor::class]),
        };
    }
}
