<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitShaPrefix;
use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query of Q3.
 */
final readonly class RequestLocationQuery implements QueryInput
{
    public function __construct(
        #[Assert\Charset('UTF-8')]
        public ?string $at = null,
        #[Assert\Range(min: 0, max: 50)]
        public ?int $context = null,
    ) {
    }

    /**
     * @throws InvalidInput Not 7 to 64 lower-case hex characters
     */
    public function at(): ?CommitShaPrefix
    {
        return null === $this->at || '' === $this->at ? null : CommitShaPrefix::fromString($this->at, 'at');
    }
}
