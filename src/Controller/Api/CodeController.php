<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\BlobStream;
use App\Application\FetchRepository;
use App\Application\Query\FileQuery;
use App\Application\Query\TreeQuery;
use App\Controller\Api\Input\FilePathQuery;
use App\Controller\Api\Input\TreeListingQuery;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * V6 to V8 of docs/specs/rest-api.md section 5: reads at the review commit.
 */
#[Route('/api/v1/reviews/{reviewId}', name: 'api_review_')]
final class CodeController extends AbstractController
{
    /** docs/specs/README.md section 6. Increase `v1` when the FileContent representation changes. */
    private const string FILE_ETAG_VERSION = 'v1';

    private const array FILE_CACHE_HEADERS = ['Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization, Cookie'];

    public function __construct(
        private readonly FileQuery $files,
        private readonly FetchRepository $fetchRepository,
    ) {
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function listTree(
        #[MapEntity(id: 'reviewId')] Review $review,
        TreeQuery $trees,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        TreeListingQuery $query = new TreeListingQuery(),
    ): JsonResponse {
        $dir = '' === $query->dir ? null : RepoPath::fromString($query->dir, 'dir');

        return $this->json($trees->list($review, $dir, $query->recursive, $query->limit, $query->cursor));
    }

    #[Route('/file', name: 'file', methods: ['GET'])]
    public function getFile(
        #[MapEntity(id: 'reviewId')] Review $review,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        FilePathQuery $query,
    ): Response {
        $path = RepoPath::fromString((string) $query->path);
        $gitDir = $this->fetchRepository->readyClonePath($review->getRepository()->getId());
        $entry = $this->files->entry($gitDir, $review, $path);
        $headers = ['ETag' => 'W/"'.self::FILE_ETAG_VERSION."-{$entry->objectId}\""] + self::FILE_CACHE_HEADERS;
        $notModified = new Response(null, Response::HTTP_OK, $headers);

        return $notModified->isNotModified($request) ? $notModified : $this->json($this->files->get($gitDir, $review, $entry), Response::HTTP_OK, $headers);
    }

    #[Route('/raw', name: 'raw', methods: ['GET'])]
    public function getRaw(
        #[MapEntity(id: 'reviewId')] Review $review,
        BlobStream $blobs,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        FilePathQuery $query,
    ): Response {
        $path = RepoPath::fromString((string) $query->path);
        $gitDir = $this->fetchRepository->readyClonePath($review->getRepository()->getId());

        return $blobs->response($gitDir, BlobSha::fromString($this->files->entry($gitDir, $review, $path)->objectId), $request);
    }
}
