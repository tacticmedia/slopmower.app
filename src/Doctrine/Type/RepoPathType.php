<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\Git\RepoPath;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * @extends StringValueType<RepoPath>
 */
#[AsDbalType(name: self::NAME)]
final class RepoPathType extends StringValueType
{
    public const string NAME = 'repo_path';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    protected function name(): string
    {
        return self::NAME;
    }

    protected function valueClass(): string
    {
        return RepoPath::class;
    }

    protected function fromString(string $value): RepoPath
    {
        return RepoPath::fromString($value);
    }
}
