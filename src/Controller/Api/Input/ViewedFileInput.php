<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/ViewedFileInput. The path rules are those of {@see \App\Domain\Git\RepoPath}.
 */
final readonly class ViewedFileInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?string $path = null,
        #[Assert\NotNull]
        public ?bool $viewed = null,
    ) {
    }
}
