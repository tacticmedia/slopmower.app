<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Git\CommitSha;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * @extends StringValueType<CommitSha>
 */
#[AsDbalType(name: self::NAME)]
final class CommitShaType extends StringValueType
{
    public const string NAME = 'commit_sha';
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
        return CommitSha::class;
    }

    protected function fromString(string $value): CommitSha
    {
        return CommitSha::fromString($value);
    }
}
