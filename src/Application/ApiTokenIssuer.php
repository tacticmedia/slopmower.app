<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\ApiToken\ApiTokenSecret;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\RepositoryId;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\RepositoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Ulid;

/**
 * T2 of docs/specs/rest-api-requests.md section 6.
 */
final class ApiTokenIssuer
{
    public const int MAX_ACTIVE_TOKENS = 50;

    private const string MIN_LIFETIME = '+1 minute';
    private const string MAX_LIFETIME = '+366 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiTokenRepository $tokens,
        private readonly RepositoryRepository $repositories,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The expiry is body validation, so it comes before the 404 of the body ID (docs/specs/README.md section 4).
     *
     * @throws InvalidInput An expiry outside the window, or a 51st active token
     * @throws NotFound     A repository that the tenant does not have
     */
    public function issue(User $user, string $name, ?RepositoryId $repositoryId, ?\DateTimeImmutable $expiresAt): IssuedApiToken
    {
        $now = $this->clock->now();

        if (null !== $expiresAt && ($expiresAt < $now->modify(self::MIN_LIFETIME) || $expiresAt > $now->modify(self::MAX_LIFETIME))) {
            throw InvalidInput::field('expiresAt', 'Must be null, or from now plus 1 minute to now plus 366 days.');
        }

        $repository = null === $repositoryId
            ? null
            : $this->repositories->find(Ulid::fromString($repositoryId->toString())) ?? throw new NotFound('The repository does not exist.');

        // The count and the insert form one step, so two parallel requests cannot pass the limit.
        $lock = $this->lockFactory->createLock("api-token-issue:{$user->getId()}", ttl: null);
        $lock->acquire(true);

        try {
            if ($this->tokens->countActive($user, $now) >= self::MAX_ACTIVE_TOKENS) {
                throw InvalidInput::field('', 'A user has at most 50 active tokens. Revoke one first.');
            }

            $secret = ApiTokenSecret::generate();
            $token = new ApiToken($user, $name, $repository, $secret, $now, $expiresAt);
            $this->entityManager->persist($token);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return new IssuedApiToken($token, $secret);
    }
}
