<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/ApproveProposalInput.
 */
final readonly class ApproveProposalInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Ulid]
        public ?string $proposalEventId = null,
        #[Assert\Length(max: 65536)]
        public ?string $note = null,
    ) {
    }
}
