<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\Identifier;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\PullRequestUrl;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * IDs as upper-case ULIDs, SHAs in lower case, paths, refs, names, and URLs as they are.
 */
final class ValueObjectNormalizer implements NormalizerInterface
{
    private const array TYPES = [Identifier::class, CommitSha::class, BlobSha::class, RepoPath::class, RefName::class, RepositoryName::class, PullRequestUrl::class];

    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        \assert($data instanceof \Stringable);

        return (string) $data;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return array_any(self::TYPES, static fn (string $type): bool => $data instanceof $type);
    }

    public function getSupportedTypes(?string $format): array
    {
        return array_fill_keys(self::TYPES, true);
    }
}
