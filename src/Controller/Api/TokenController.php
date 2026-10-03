<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\ApiTokenIssuer;
use App\Controller\Api\Input\CreateTokenInput;
use App\Domain\Identity\RepositoryId;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * T1 to T3 of docs/specs/rest-api-requests.md section 6.
 */
#[Route('/api/v1/tokens', name: 'api_token_')]
#[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
final class TokenController extends AbstractController
{
    public function __construct(private readonly CurrentActor $currentActor)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(ApiTokenRepository $tokens): JsonResponse
    {
        return $this->json(['items' => $tokens->findBy(['user' => $this->currentActor->sessionUser()], ['createdAt' => 'DESC', 'id' => 'DESC'])]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload(acceptFormat: 'json')] CreateTokenInput $input, ApiTokenIssuer $issuer): JsonResponse
    {
        \assert(null !== $input->name);
        $repositoryId = null === $input->repositoryId ? null : RepositoryId::fromString($input->repositoryId, 'repositoryId');

        return $this->json($issuer->issue($this->currentActor->sessionUser(), $input->name, $repositoryId, $input->expiresAt), Response::HTTP_CREATED);
    }

    /**
     * The row stays, so T1 lists the revoked token.
     */
    #[Route('/{tokenId}', name: 'revoke', methods: ['DELETE'])]
    public function revoke(#[MapEntity(id: 'tokenId')] ApiToken $token, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $token->revoke($clock->now());
        $entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
