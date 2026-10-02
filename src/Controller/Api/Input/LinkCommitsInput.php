<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Identity\RequestId;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/LinkCommitsInput. IDs are case-insensitive, so they are unique in upper case.
 */
final readonly class LinkCommitsInput
{
    /**
     * @param list<string>|null $requestIds
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Regex(pattern: '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', message: 'Must be a full commit SHA: 40 or 64 lower-case hex characters.')]
        public ?string $commitSha = null,
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
