<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\FetchRepository;
use App\Application\Query\DeclarationList;
use App\Application\Query\FileQuery;
use App\Controller\Api\Input\DeclarationQuery;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Git\GitReader;
use App\Index\IndexSql;
use App\Index\PhpSymbolExtractor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * V14 of docs/specs/rest-api.md section 5: resolves the name on demand and reads its declarations from the index.
 */
final class DeclarationController extends AbstractController
{
    #[Route('/api/v1/reviews/{reviewId}/declarations', name: 'api_review_declarations', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'reviewId')] Review $review,
        FetchRepository $fetchRepository,
        FileQuery $files,
        GitReader $reader,
        PhpSymbolExtractor $extractor,
        IndexSql $indexSql,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        DeclarationQuery $query,
    ): JsonResponse {
        $path = RepoPath::fromString((string) $query->path);

        if (!$extractor->supports($path)) {
            throw InvalidInput::field('path', 'Must name a PHP file, *.php.');
        }

        $repository = $review->getRepository();
        $gitDir = $fetchRepository->readyClonePath($repository->getId());
        $entry = $files->entry($gitDir, $review, $path);
        $blob = $entry->isRenderableFile() ? $reader->readBlob($gitDir, BlobSha::fromString($entry->objectId)) : null;
        $source = null === $blob || $blob->isBinary() ? null : $blob->bytes;
        $references = match (true) {
            null === $source => [],
            null !== $query->name => $extractor->referencesNamed($source, $query->name),
            null !== $query->line && null !== $query->column => $extractor->referencesAt($source, $query->line, $query->column),
            default => [],
        };

        return $this->json(new DeclarationList($review->getId(), $indexSql->declarations($repository->getTenantId(), $repository->getId(), $review->getCommitSha(), $references)));
    }
}
