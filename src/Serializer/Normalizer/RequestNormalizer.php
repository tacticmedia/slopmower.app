<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\RequestView;
use App\Domain\Event\Codec\AnchorCodec;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * request.schema.json: the RequestSummary with the full anchor, then the fields of a full request.
 */
final class RequestNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof RequestView);
        $state = $data->summary->state;
        $review = $data->summary->request->getReview();

        $summary = $this->normalizer->normalize($data->summary, $format, $context);
        $details = $this->normalizer->normalize([
            'body' => $state->body,
            'reviewState' => $review->getState(),
            'reviewCommitSha' => $review->getCommitSha(),
            'location' => $data->location,
            'excerpt' => $data->excerpt,
            'thread' => $data->thread,
            'commits' => $data->commits,
            'pullRequestUrls' => $state->pullRequestUrls,
            'pullRequests' => $data->pullRequests,
            'allowedActions' => $data->allowedActions,
            'delivery' => $review->getDelivery()->toPayload(),
            'capabilities' => ['pullRequests' => $review->getRepository()->getSource()->hasPullRequests()],
        ], $format, $context);
        \assert(\is_array($summary) && \is_array($details));

        $anchor = null === $data->anchor ? null : AnchorCodec::encode($data->anchor);

        if (\is_array($anchor['snapshot'] ?? null)) {
            // D113: null text when the rebuild failed.
            $anchor['snapshot'] += ['lines' => null, 'before' => null, 'after' => null];
        }

        return [...$summary, 'anchor' => $anchor, ...$details];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof RequestView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [RequestView::class => true];
    }
}
