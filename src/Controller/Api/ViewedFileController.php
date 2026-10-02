<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\FetchRepository;
use App\Application\Query\FileQuery;
use App\Controller\Api\Input\ViewedFileInput;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\ViewedFileRepository;
use App\Repository\ViewedFileSql;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * V11 and V12 of docs/specs/rest-api.md section 5. The marks belong to the caller and never reset inside a review.
 */
#[Route('/api/v1/reviews/{reviewId}/viewed-files', name: 'api_viewed_file_')]
#[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
final class ViewedFileController extends AbstractController
{
    public function __construct(
        private readonly ViewedFileRepository $viewedFiles,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[MapEntity(id: 'reviewId')] Review $review): JsonResponse
    {
        return $this->viewedFilesOf($review);
    }

    /**
     * Idempotent per path.
     */
    #[Route('', name: 'mark', methods: ['PUT'])]
    public function mark(
        #[MapEntity(id: 'reviewId')] Review $review,
        #[MapRequestPayload(acceptFormat: 'json')] ViewedFileInput $input,
        FetchRepository $fetchRepository,
        FileQuery $files,
        ViewedFileSql $marks,
    ): JsonResponse {
        \assert(null !== $input->path && null !== $input->viewed);
        $path = RepoPath::fromString($input->path);

        try {
            $files->entry($fetchRepository->readyClonePath($review->getRepository()->getId()), $review, $path);
        } catch (NotFound) {
            throw InvalidInput::field('path', 'No file has this path at the review commit.');
        } catch (RepositoryUnavailable $unavailable) {
            // review_closed comes before repository_unavailable in the check order of docs/specs/README.md section 4.
            $review->assertOpen();

            throw $unavailable;
        }

        $review->assertOpen();
        $userId = $this->currentActor->sessionUser()->getId();

        if ($input->viewed) {
            $marks->mark($review->getTenantId(), $userId, $review->getId(), $path);
        } else {
            $marks->unmark($review->getTenantId(), $userId, $review->getId(), $path);
        }

        return $this->viewedFilesOf($review);
    }

    private function viewedFilesOf(Review $review): JsonResponse
    {
        return $this->json(['reviewId' => $review->getId(), 'paths' => $this->viewedFiles->paths($this->currentActor->sessionUser()->getId(), $review->getId())]);
    }
}
