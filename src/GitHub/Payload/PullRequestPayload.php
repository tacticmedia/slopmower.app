<?php

declare(strict_types=1);

namespace App\GitHub\Payload;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;

/**
 * A GitHub pull request object, as `GET /repos/{owner}/{repo}/pulls/{number}` and the `pull_request` webhook send it.
 * The `pull_request` object of some actions has no `merged`, so {@see self::merged()} reads `merged_at`.
 */
final readonly class PullRequestPayload
{
    /**
     * @param string $state `open` or `closed`
     */
    public function __construct(
        public int $number,
        public string $title,
        public string $state,
        #[SerializedName('updated_at')]
        public \DateTimeImmutable $updatedAt,
        #[SerializedPath('[head][sha]')]
        public string $headSha,
        #[SerializedName('merged_at')]
        public ?\DateTimeImmutable $mergedAt = null,
        public bool $draft = false,
        /** Null when the fork of the head is deleted. */
        #[SerializedPath('[head][repo?][full_name]')]
        public ?string $headRepositoryFullName = null,
    ) {
    }

    public function merged(): bool
    {
        return null !== $this->mergedAt;
    }
}
