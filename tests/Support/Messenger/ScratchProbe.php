<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;

/**
 * {@see ScratchProbeHandler} greps the commit twice through the code reader of the repository, then fails.
 */
final readonly class ScratchProbe
{
    public function __construct(public RepositoryId $repositoryId, public CommitSha $commit)
    {
    }
}
