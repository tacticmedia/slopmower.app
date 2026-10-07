<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Identity\RequestId;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/LinkPullRequestInput. IDs are case-insensitive, so they are unique in upper case. The
 * decider checks a GitHub URL against the repository of the review.
 */
final readonly class LinkPullRequestInput
{
    /**
     * @param list<string>|null $requestIds
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(max: 2048)]
        #[Assert\Regex(pattern: '#^https://\S+$#uD', message: 'Must be an https URL of at most 2,048 characters without whitespace.')]
        public ?string $url = null,
        #[Assert\NotNull]
        #[Assert\Count(min: 1, max: 50)]
        #[Assert\Unique(normalizer: 'strtoupper')]
        #[Assert\All([new Assert\NotNull(), new Assert\Ulid()])]
        public ?array $requestIds = null,
        #[Assert\Length(max: 65536)]
        public ?string $note = null,
    ) {
    }

    /**
     * @return list<RequestId>
     */
    public function requestIds(): array
    {
        return array_map(static fn (string $id): RequestId => RequestId::fromString($id), $this->requestIds ?? []);
    }
}
