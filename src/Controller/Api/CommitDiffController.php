<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Anchoring\Locator;
use App\Application\FetchRepository;
use App\Application\Query\CommitDiffFileView;
use App\Application\Query\CommitDiffView;
use App\Application\Query\LocationView;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Location\LocationTarget;
use App\Domain\Request\CommitLinkState;
use App\Entity\ChangeRequest;
use App\Git\ChangedFile;
use App\Git\GitReader;
use App\Git\TenantDataPaths;
use App\Git\TreeEntry;
use App\Repository\CommitLinkRepository;
use App\Repository\ReviewEventRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Q9 of docs/specs/rest-api-requests.md section 2.
 */
final class CommitDiffController extends AbstractController
{
    public const int MAX_FILES = 300;

    public function __construct(private readonly GitReader $reader)
    {
    }

    #[Route('/api/v1/requests/{requestId}/commits/{commitSha}', name: 'api_request_commit_diff', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        string $commitSha,
        CommitLinkRepository $links,
        FetchRepository $fetchRepository,
        TenantDataPaths $paths,
        ReviewEventRepository $events,
        Locator $locator,
    ): JsonResponse {
        $sha = CommitSha::fromString($commitSha);
        $link = $links->findOneBy(['request' => $request, 'commitSha' => $sha]) ?? throw new NotFound('The commit is not linked to this request.');

        if (CommitLinkState::Pending === $link->getState()) {
            throw new CommitsNotAvailable([$sha]);
        }

        $review = $request->getReview();
        $repository = $fetchRepository->cloneReady($review->getRepository()->getId());
        $gitDir = $paths->clonePath($repository->getTenantId(), $repository->getId());

        try {
            $commit = $this->reader->commitDetails($gitDir, $sha);
        } catch (CommitNotFound) {
            // An available link whose commit left the clone.
            throw new CommitsNotAvailable([$sha]);
        }

        $openedEventId = $events->findOpenedEventIds($review->getId())[$request->getId()->toString()] ?? null;
        $location = null === $openedEventId ? null : $locator->locate($repository, $openedEventId, $request->getAnchor(), $commit->sha);
        // Null for a null or orphaned location: then the anchor path decides.
        $locatedPath = $location?->path?->toString();
        $anchorPath = $request->getAnchor()->path->toString();
        $changes = $this->reader->changedFiles($gitDir, $commit->parentSha, $commit->sha);
        $files = array_slice($this->files(
            $gitDir,
            $changes,
            static fn (ChangedFile $change): bool => null === $locatedPath ? \in_array($anchorPath, [$change->path, $change->oldPath], true) : $change->path === $locatedPath,
        ), 0, self::MAX_FILES);

        return $this->json(new CommitDiffView(
            $request->getId(),
            $commit,
            null === $location ? null : new LocationView(LocationTarget::LinkedCommit, $location),
            $files,
            // Also true when a path that a RepoPath rejects is left out.
            count($changes) > count($files),
        ));
    }

    /**
     * Files that touch the anchor come first, so the cut at {@see MAX_FILES} keeps them. A path that a RepoPath
     * rejects is left out, because the schema cannot carry it.
     *
     * @param list<ChangedFile>           $changes
     * @param \Closure(ChangedFile): bool $touchesAnchor
     *
     * @return list<CommitDiffFileView>
     */
    private function files(string $gitDir, array $changes, \Closure $touchesAnchor): array
    {
        $objectIds = array_merge(...array_map(static fn (ChangedFile $change): array => array_filter([$change->oldObjectId, $change->newObjectId]), $changes));
        $sizesBytes = $this->reader->objectSizes($gitDir, array_values(array_unique($objectIds)));
        $files = [];

        foreach ($changes as $change) {
            try {
                $path = RepoPath::fromString($change->path);
                $oldPath = null === $change->oldPath ? null : RepoPath::fromString($change->oldPath);
            } catch (InvalidInput) {
                continue;
            }

            $largestSideBytes = max($sizesBytes[$change->oldObjectId ?? ''] ?? 0, $sizesBytes[$change->newObjectId ?? ''] ?? 0);
            $files[] = new CommitDiffFileView($change, $path, $oldPath, $largestSideBytes > TreeEntry::MAX_RENDERABLE_BYTES, $touchesAnchor($change));
        }

        usort($files, static fn (CommitDiffFileView $first, CommitDiffFileView $second): int => $second->touchesAnchor <=> $first->touchesAnchor);

        return $files;
    }
}
