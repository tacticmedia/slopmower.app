<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The path rules are those of {@see \App\Domain\Git\RepoPath}.
 */
final readonly class FilePathQuery implements QueryInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?string $path = null,
    ) {
    }
}
