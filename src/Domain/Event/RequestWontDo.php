<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\PayloadReader;

final readonly class RequestWontDo implements DomainEvent
{
    public const int MIN_TRIMMED_REASON_LENGTH = 20;

    public function __construct(
        public string $reason,
        string $field = 'reason',
    ) {
        if (mb_strlen(mb_trim($reason, encoding: 'UTF-8'), 'UTF-8') < self::MIN_TRIMMED_REASON_LENGTH) {
            throw InvalidInput::field($field, \sprintf('must have %d or more characters after trimming', self::MIN_TRIMMED_REASON_LENGTH));
        }
    }

    public static function type(): EventType
    {
        return EventType::RequestWontDo;
    }

    /**
     * @return array{reason: string}
     */
    public function toPayload(): array
    {
        return ['reason' => $this->reason];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return new self($reader->string('reason'), $reader->field('reason'));
    }
}
