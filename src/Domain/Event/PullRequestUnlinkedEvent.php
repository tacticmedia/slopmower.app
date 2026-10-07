<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\RequestId;
use App\Domain\Request\PullRequestUrl;

/**
 * The request is in the payload, because the `requestId` column is null for pull request events.
 */
final readonly class PullRequestUnlinkedEvent implements DomainEvent
{
    public function __construct(
        public PullRequestUrl $url,
        public RequestId $requestId,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::PullRequestUnlinked;
    }

    public function toPayload(): array
    {
        return [
            'url' => $this->url->toString(),
            'requestId' => $this->requestId->toString(),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return $reader->build(static fn (): self => new self(
            PullRequestUrl::fromString($reader->string('url')),
            $reader->id('requestId', RequestId::class),
        ));
    }
}
