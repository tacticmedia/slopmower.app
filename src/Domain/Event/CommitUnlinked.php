<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;

final readonly class CommitUnlinked implements DomainEvent
{
    public function __construct(
        public CommitSha $commitSha,
        public RequestId $requestId,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::CommitUnlinked;
    }

    /**
     * @return array{commitSha: string, requestId: string}
     */
    public function toPayload(): array
    {
        return [
            'commitSha' => $this->commitSha->toString(),
            'requestId' => $this->requestId->toString(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return new self(
            $reader->commitSha('commitSha'),
            $reader->id('requestId', RequestId::class),
        );
    }
}
