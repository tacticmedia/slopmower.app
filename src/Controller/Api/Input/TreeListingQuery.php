<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TreeListingQuery implements QueryInput
{
    /**
     * @param string $dir A RepoPath, or '' for the root
     */
    public function __construct(
        public string $dir = '',
        public bool $recursive = false,
        #[Assert\Range(min: 1, max: 5000)]
        public int $limit = 1000,
        #[Assert\Charset('UTF-8')]
        public ?string $cursor = null,
    ) {
    }
}
