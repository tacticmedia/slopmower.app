<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\RequestSummaryView;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * request-summary.schema.json.
 */
final class RequestSummaryNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const int MAX_SUMMARY_CHARACTERS = 200;

    private const array ANCHOR_SUMMARY_FIELDS = ['kind' => true, 'path' => true, 'range' => true, 'symbol' => true];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof RequestSummaryView);
        $state = $data->state;

        $normalized = $this->normalizer->normalize([
            'id' => $state->id,
            'reviewId' => $state->reviewId,
            'repositoryId' => $data->request->getReview()->getRepository()->getId(),
            'status' => $state->status,
            'awaiting' => $data->request->getAwaiting(),
            'resolution' => $state->resolution,
            'closed' => $state->closed(),
            'anchor' => null === $state->anchor ? null : array_intersect_key(AnchorCodec::encode($state->anchor), self::ANCHOR_SUMMARY_FIELDS),
            'summary' => self::summary($state->body),
            'author' => $state->author,
            'claimedBy' => $state->claimedBy,
            'claimedAt' => $state->claimedAt,
            'commentCount' => $data->commentCount,
            'commitCount' => count($state->links),
            'pendingCommitCount' => count(array_filter($state->links, static fn (LinkedCommit $link): bool => CommitLinkState::Pending === $link->state)),
            'outdated' => $data->request->getOutdated(),
            'createdAt' => $state->createdAt,
            'updatedAt' => $state->updatedAt,
            'lastEventSeq' => $state->lastEventSeq,
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof RequestSummaryView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [RequestSummaryView::class => true];
    }

    /**
     * The first non-blank line of the body, trimmed. A longer line is cut and ends with an ellipsis.
     */
    private static function summary(string $body): string
    {
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if ('' !== $line) {
                return mb_strlen($line, 'UTF-8') > self::MAX_SUMMARY_CHARACTERS ? mb_substr($line, 0, self::MAX_SUMMARY_CHARACTERS - 1, 'UTF-8')."\u{2026}" : $line;
            }
        }

        throw new \UnexpectedValueException('A request body has a non-blank line.');
    }
}
