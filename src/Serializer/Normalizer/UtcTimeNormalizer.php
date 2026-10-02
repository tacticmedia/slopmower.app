<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Domain\Event\Codec\TimeCodec;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalization only: input times keep the framework DateTimeNormalizer, which also reads offsets other than Z.
 */
final class UtcTimeNormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        \assert($data instanceof \DateTimeInterface);

        return TimeCodec::encode(\DateTimeImmutable::createFromInterface($data));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof \DateTimeInterface;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [\DateTimeInterface::class => true];
    }
}
