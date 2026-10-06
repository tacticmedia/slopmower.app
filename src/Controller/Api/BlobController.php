<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\FetchRepository;
use App\Application\Query\BlobStream;
use App\Domain\Git\BlobSha;
use App\Entity\Repository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * R7 of docs/specs/rest-api.md section 4.
 */
final class BlobController extends AbstractController
{
    #[Route('/api/v1/repositories/{repositoryId}/blobs/{blobSha}', name: 'api_repository_blob', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'repositoryId')] Repository $repository,
        string $blobSha,
        FetchRepository $fetchRepository,
        BlobStream $blobs,
        Request $request,
    ): Response {
        $blob = BlobSha::fromString($blobSha);

        return $blobs->response($fetchRepository->readyClonePath($repository->getId()), $blob, $request);
    }
}
