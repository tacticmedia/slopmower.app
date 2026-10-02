<?php

declare(strict_types=1);

namespace App\Webhook\Handler;

use App\Application\FetchRepository;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Repository\RepositoryAccess;
use App\Message\GitHubPushJob;
use App\Repository\RepositoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * `push`: a fetch as R5 requests it. The steps of docs/specs/webhooks-and-live-updates.md section 2.2 follow the fetch.
 */
#[AsMessageHandler]
final class PushHandler
{
    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly FetchRepository $fetchRepository,
    ) {
    }

    public function __invoke(GitHubPushJob $job): void
    {
        $repository = $this->repositories->findGitHub($job->githubRepositoryId);

        if (RepositoryAccess::Active !== $repository?->getAccess()) {
            return;
        }

        try {
            $this->fetchRepository->request($repository->getId());
        } catch (NotFound|RepositoryUnavailable) {
            // Deleted, or its access removed, since the lookup.
        }
    }
}
