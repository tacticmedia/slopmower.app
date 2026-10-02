<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Repository\RepositorySource;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CandidateListQuery implements QueryInput
{
    /**
     * @param string|null $q Case-insensitive substring of the name
     */
    public function __construct(
        public ?RepositorySource $source = null,
        #[Assert\Charset('UTF-8')]
        public ?string $q = null,
        public bool $includeConnected = true,
    ) {
    }
}
