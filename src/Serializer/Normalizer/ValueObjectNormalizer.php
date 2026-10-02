<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\Identifier;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * IDs as upper-case ULIDs, SHAs in lower case.
 */
final class ValueObjectNormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        \assert($data instanceof Identifier || $data instanceof CommitSha);

        return $data->toString();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Identifier || $data instanceof CommitSha;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Identifier::class => true, CommitSha::class => true];
    }
}
