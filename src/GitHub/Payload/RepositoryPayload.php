<?php

declare(strict_types=1);

namespace App\GitHub\Payload;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A GitHub repository object, as the installation repository listing and `GET /repos/{owner}/{repo}` send it.
 */
final readonly class RepositoryPayload
{
    public function __construct(
        public int $id,
        #[SerializedName('full_name')]
        public string $fullName,
        public bool $private,
        #[SerializedName('html_url')]
        public string $htmlUrl,
        #[SerializedName('default_branch')]
        public string $defaultBranch,
    ) {
    }
}
