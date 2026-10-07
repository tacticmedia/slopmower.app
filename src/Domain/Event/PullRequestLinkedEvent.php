<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\UniqueIds;
use App\Domain\Request\PullRequestUrl;

final readonly class PullRequestLinkedEvent implements DomainEvent
{
    /**
     * @param list<RequestId> $requestIds The newly linked requests
     */
    public function __construct(
        public PullRequestUrl $url,
        public array $requestIds,
        public ?string $note,
    ) {
        $violations = UniqueIds::violations($requestIds, 'requestIds');

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }
    }

    public static function type(): EventType
    {
        return EventType::PullRequestLinked;
    }

    public function toPayload(): array
    {
        return [
            'url' => $this->url->toString(),
            'requestIds' => array_map(static fn (RequestId $id): string => $id->toString(), $this->requestIds),
            'note' => $this->note,
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return $reader->build(static fn (): self => new self(
            PullRequestUrl::fromString($reader->string('url')),
            $reader->idList('requestIds', RequestId::class),
            $reader->nullableString('note'),
        ));
    }
}
