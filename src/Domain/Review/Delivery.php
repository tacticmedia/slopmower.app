<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Event\Codec\PayloadReader;

/**
 * How the agent delivers its work (D85). Null lets the agent decide.
 */
final readonly class Delivery
{
    public function __construct(
        public ?Workspace $workspace = null,
        public ?bool $pullRequest = null,
    ) {
    }

    /**
     * @return array{workspace: ?string, pullRequest: ?bool}
     */
    public function toPayload(): array
    {
        return ['workspace' => $this->workspace?->value, 'pullRequest' => $this->pullRequest];
    }

    public static function fromPayload(PayloadReader $reader): self
    {
        return new self($reader->nullableEnum('workspace', Workspace::class), $reader->nullableBool('pullRequest'));
    }
}
