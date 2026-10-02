<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\FetchRepository;
use App\Application\Query\FileQuery;
use App\Application\Query\SymbolList;
use App\Controller\Api\Input\FilePathQuery;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Index\BlobSymbols;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * V9 of docs/specs/rest-api.md section 5. Parses a blob that the index job has not reached yet, so it never waits.
 */
final class SymbolController extends AbstractController
{
    /** docs/specs/README.md section 6. Increase `v1` when the SymbolList representation changes. */
    private const string ETAG_VERSION = 'v1';

    #[Route('/api/v1/reviews/{reviewId}/symbols', name: 'api_review_symbols', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'reviewId')] Review $review,
        Request $request,
        FetchRepository $fetchRepository,
        FileQuery $files,
        BlobSymbols $blobSymbols,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        FilePathQuery $query,
    ): Response {
        $path = RepoPath::fromString((string) $query->path);
        $gitDir = $fetchRepository->readyClonePath($review->getRepository()->getId());
        $entry = $files->entry($gitDir, $review, $path);
        $headers = ['ETag' => 'W/"'.self::ETAG_VERSION."-{$entry->objectId}\"", 'Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization, Cookie'];
        $notModified = new Response(null, Response::HTTP_OK, $headers);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $symbols = SymbolList::of($review->getId(), $path, BlobSha::fromString($entry->objectId), $blobSymbols->of($review->getRepository(), $gitDir, $entry));

        return $this->json($symbols, Response::HTTP_OK, $headers);
    }
}
