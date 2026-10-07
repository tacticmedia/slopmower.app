<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/RejectProposalInput.
 */
final readonly class RejectProposalInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Ulid]
        public ?string $proposalEventId = null,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $note = null,
    ) {
    }
}
