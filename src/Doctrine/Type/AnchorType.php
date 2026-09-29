<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Anchor\Anchor;
use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\Codec\PayloadReader;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\SerializationFailed;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * The `request.opened` payload form of an anchor, so the projection and the event log agree.
 */
#[AsDbalType(name: self::NAME)]
final class AnchorType extends Type
{
    public const string NAME = 'anchor';
    private const string FIELD = 'anchor';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonbTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof Anchor) {
            throw InvalidType::new($value, self::NAME, ['null', Anchor::class]);
        }

        try {
            return PayloadJson::encode(AnchorCodec::encode($value));
        } catch (\JsonException $error) {
            throw SerializationFailed::new($value, self::NAME, $error->getMessage(), $error);
        }
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Anchor
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw ValueNotConvertible::new($value, self::NAME);
        }

        try {
            return AnchorCodec::decode(PayloadReader::of(PayloadJson::decode($value, self::FIELD), self::FIELD));
        } catch (InvalidInput $error) {
            throw ValueNotConvertible::new($value, self::NAME, null, $error);
        }
    }
}
