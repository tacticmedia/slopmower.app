<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\IssueStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/IssueStatusInput.
 */
final readonly class IssueStatusInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?IssueStatus $status = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $note = null,
        #[Assert\Length(min: 1, max: 64)]
        public ?string $reviewer = null,
        #[Assert\Length(min: 1, max: 64)]
        public ?string $session = null,
    ) {
    }
}
