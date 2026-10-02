<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\IndexStatus;
use App\Application\Query\ReviewView;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * review.schema.json.
 */
final class ReviewNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof ReviewView);
        $review = $data->review;

        $normalized = $this->normalizer->normalize([
            'id' => $review->getId(),
            'repositoryId' => $review->getRepository()->getId(),
            'repositoryName' => $review->getRepository()->getName()->toString(),
            'title' => $review->getTitle(),
            'ref' => $review->getRef()->toString(),
            'commitSha' => $review->getCommitSha(),
            'state' => $review->getState(),
            'startedBy' => $review->getStartedBy(),
            'createdAt' => $review->getCreatedAt(),
            'closedAt' => $review->getClosedAt(),
            'closedBy' => $review->getClosedBy(),
            'closeNote' => $review->getCloseNote(),
            'index' => IndexStatus::of($review),
            'counts' => [
                'total' => array_sum($data->requestsByStatus),
                'byStatus' => $data->requestsByStatus,
                'byAwaiting' => $data->requestsByAwaiting,
                'outdated' => $data->outdatedRequests,
            ],
            'lastEventSeq' => $review->getLastEventSeq(),
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ReviewView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [ReviewView::class => true];
    }
}
