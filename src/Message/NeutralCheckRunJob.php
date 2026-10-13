<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * The neutral check run of a pull request that the tenant does not link: docs/specs/webhooks-and-live-updates.md,
 * "Check run".
 */
#[AsMessage('async')]
final readonly class NeutralCheckRunJob
{
    /**
     * @param int|null $number Null for a rerequested run that names no pull request
     */
    public function __construct(
        public RepositoryId $repositoryId,
        public ?int $number,
        public CommitSha $headSha,
    ) {
    }
}
