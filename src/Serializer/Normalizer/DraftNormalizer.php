<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Domain\Event\Codec\AnchorCodec;
use App\Entity\Draft;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * draft.schema.json.
 */
final class DraftNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof Draft);
        $anchor = $data->getAnchor();

        $normalized = $this->normalizer->normalize([
            'id' => $data->getId(),
            'reviewId' => $data->getReview()->getId(),
            'kind' => $data->getKind(),
            'anchor' => null === $anchor ? null : AnchorCodec::encode($anchor),
            'requestId' => $data->getRequest()?->getId(),
            'title' => $data->getTitle(),
            'body' => $data->getBody(),
            'requireApproval' => $data->requiresApproval(),
            'createdAt' => $data->getCreatedAt(),
            'updatedAt' => $data->getUpdatedAt(),
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Draft;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Draft::class => true];
    }
}
