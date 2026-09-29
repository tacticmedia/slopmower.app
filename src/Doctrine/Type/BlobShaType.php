<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Git\BlobSha;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * @extends StringValueType<BlobSha>
 */
#[AsDbalType(name: self::NAME)]
final class BlobShaType extends StringValueType
{
    public const string NAME = 'blob_sha';
    private const int MAX_LENGTH = 64;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => self::MAX_LENGTH] + $column);
    }

    protected function name(): string
    {
        return self::NAME;
    }

    protected function valueClass(): string
    {
        return BlobSha::class;
    }

    protected function fromString(string $value): BlobSha
    {
        return BlobSha::fromString($value);
    }
}
