<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/UnlinkPullRequestInput. The decider checks a GitHub URL against the repository of the review.
 */
final readonly class UnlinkPullRequestInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(max: 2048)]
        #[Assert\Regex(pattern: '#^https://\S+$#uD', message: 'Must be an https URL of at most 2,048 characters without whitespace.')]
        public ?string $url = null,
    ) {
    }
}
