<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\IssuedApiToken;
use App\Controller\Api\Input\CreateTokenInput;
use App\Domain\ApiToken\ApiTokenSecret;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\RepositoryId;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\RepositoryRepository;
use App\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * T1 to T3 of docs/specs/rest-api-requests.md section 6.
 */
#[Route('/api/v1/tokens', name: 'api_token_')]
#[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
final class TokenController extends AbstractController
{
    private const int MAX_ACTIVE_TOKENS = 50;
    private const string MIN_LIFETIME = '+1 minute';
    private const string MAX_LIFETIME = '+366 days';

    public function __construct(private readonly CurrentActor $currentActor)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(ApiTokenRepository $tokens): JsonResponse
    {
        return $this->json(['items' => $tokens->findBy(['user' => $this->currentActor->sessionUser()], ['createdAt' => 'DESC', 'id' => 'DESC'])]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')] CreateTokenInput $input,
        ApiTokenRepository $tokens,
        RepositoryRepository $repositories,
        EntityManagerInterface $entityManager,
        LockFactory $lockFactory,
        ClockInterface $clock,
    ): JsonResponse {
        \assert(null !== $input->name);
        $repositoryId = null === $input->repositoryId ? null : RepositoryId::fromString($input->repositoryId, 'repositoryId');
        $user = $this->currentActor->sessionUser();
        $now = $clock->now();

        // The expiry is body validation, so it comes before the 404 of the body ID (docs/specs/README.md section 4).
        if (null !== $input->expiresAt && ($input->expiresAt < $now->modify(self::MIN_LIFETIME) || $input->expiresAt > $now->modify(self::MAX_LIFETIME))) {
            throw InvalidInput::field('expiresAt', 'Must be null, or from now plus 1 minute to now plus 366 days.');
        }

        $repository = null === $repositoryId
            ? null
            : $repositories->find(Ulid::fromString($repositoryId->toString())) ?? throw new NotFound('The repository does not exist.');

        // The count and the insert form one step, so two parallel requests cannot pass the limit.
        $lock = $lockFactory->createLock("api-token-issue:{$user->getId()}", ttl: null);
        $lock->acquire(true);

        try {
            if ($tokens->countActive($user, $now) >= self::MAX_ACTIVE_TOKENS) {
                throw InvalidInput::field('', 'A user has at most 50 active tokens. Revoke one first.');
            }

            $secret = ApiTokenSecret::generate();
            $token = new ApiToken($user, $input->name, $repository, $secret, $now, $input->expiresAt);
            $entityManager->persist($token);
            $entityManager->flush();
        } finally {
            $lock->release();
        }

        return $this->json(new IssuedApiToken($token, $secret), Response::HTTP_CREATED);
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
