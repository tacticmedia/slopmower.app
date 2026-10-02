<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\EventView;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Event\RequestReadyForReviewEvent;
use App\Domain\Event\RequestReleasedEvent;
use App\Domain\Event\RequestReopenedEvent;
use App\Domain\Event\RequestVerifiedEvent;
use App\Domain\Event\RequestWontDoEvent;
use App\Domain\Event\ReviewClosedEvent;
use App\Domain\Event\ReviewStartedEvent;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * event.schema.json. {@see ThreadEntryNormalizer} drops the review and request fields.
 */
final class EventNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof EventView);
        $recorded = $data->event;
        $event = $recorded->event;

        $normalized = $this->normalizer->normalize([
            'seq' => $recorded->seq,
            'eventId' => $recorded->id,
            'type' => $recorded->type,
            'reviewId' => $recorded->reviewId,
            'requestId' => $recorded->subjectRequestId(),
            'requestIds' => $recorded->requestIds(),
            'actor' => $recorded->actor,
            'occurredAt' => $recorded->occurredAt,
            'body' => self::body($event),
            'commentId' => $event instanceof RequestCommentedEvent ? $event->commentId : null,
            'question' => $event instanceof RequestCommentedEvent && $event->question,
            'batchId' => match (true) {
                $event instanceof RequestOpenedEvent, $event instanceof RequestCommentedEvent, $event instanceof BatchSubmittedEvent => $event->batchId,
                default => null,
            },
            'commitSha' => match (true) {
                $event instanceof CommitLinkedEvent, $event instanceof CommitUnlinkedEvent, $event instanceof CommitFoundEvent, $event instanceof ReviewStartedEvent => $event->commitSha,
                default => null,
            },
            'statusAfter' => $data->statusAfter,
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof EventView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [EventView::class => true];
    }

    /**
     * The `body` rules of thread-entry.schema.json and event.schema.json.
     */
    private static function body(DomainEvent $event): ?string
    {
        return match (true) {
            $event instanceof RequestOpenedEvent, $event instanceof RequestCommentedEvent => $event->body,
            $event instanceof RequestReleasedEvent, $event instanceof RequestReadyForReviewEvent, $event instanceof RequestVerifiedEvent,
            $event instanceof RequestReopenedEvent, $event instanceof CommitLinkedEvent, $event instanceof ReviewClosedEvent => $event->note,
            $event instanceof RequestWontDoEvent => $event->reason->toString(),
            $event instanceof ReviewStartedEvent => $event->title,
            $event instanceof BatchSubmittedEvent => $event->summary,
            default => null,
        };
    }
}
