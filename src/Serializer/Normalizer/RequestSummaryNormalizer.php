<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\RequestSummaryView;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Util\HtmlFilter;
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

    private readonly MarkdownConverter $markdown;

    public function __construct()
    {
        $this->markdown = new GithubFlavoredMarkdownConverter(['html_input' => HtmlFilter::STRIP]);
    }

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
            'title' => $state->title,
            'summary' => $state->title ?? $this->summary($state->body),
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
            'carriedFrom' => null === $state->carriedFrom ? null : ['reviewId' => $state->carriedFrom->reviewId, 'requestId' => $state->carriedFrom->requestId],
            'requireApproval' => $state->requireApproval,
            'suggestion' => $state->suggestion,
            'proposal' => null === $state->proposal ? null : ['eventId' => $state->proposal->eventId, 'state' => $state->proposal->state],
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
     * The plain text of the first non-blank line that has plain text, else the first non-blank line.
     */
    private function summary(string $body): string
    {
        $lines = array_filter(array_map(trim(...), explode("\n", $body)), static fn (string $line): bool => '' !== $line);

        foreach ($lines as $line) {
            $text = trim(html_entity_decode(strip_tags($this->markdown->convert($line)->getContent()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

            if ('' !== $text) {
                return self::cut($text);
            }
        }

        return self::cut(array_first($lines) ?? throw new \UnexpectedValueException('A request body has a non-blank line.'));
    }

    private static function cut(string $text): string
    {
        return mb_strlen($text, 'UTF-8') > self::MAX_SUMMARY_CHARACTERS ? mb_substr($text, 0, self::MAX_SUMMARY_CHARACTERS - 1, 'UTF-8')."\u{2026}" : $text;
    }
}
