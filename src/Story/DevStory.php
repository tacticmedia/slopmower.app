<?php

declare(strict_types=1);

namespace App\Story;

use App\Application\ConnectLocalRepository;
use App\Domain\ApiToken\ApiTokenSecret;
use App\Domain\ApiToken\ApiTokenState;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Entity\User;
use App\Factory\ApiTokenFactory;
use App\Repository\ApiTokenRepository;
use App\Security\LocalUserProvisioner;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * The local user, its agent token, and the repository APP_DEV_REPOSITORY. A second load adds no row. Loads without a
 * transaction: {@see \App\Application\FetchRepository::queueClone()} refuses an open one.
 */
#[AsFixture(name: 'dev')]
final class DevStory extends Story
{
    public const string TOKEN_NAME = 'Claude Code (dev)';

    public function __construct(
        private readonly LocalUserProvisioner $users,
        private readonly TenantContext $tenantContext,
        private readonly ApiTokenRepository $tokens,
        private readonly ConnectLocalRepository $connectLocalRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'APP_LOCAL_SIGN_IN')]
        private readonly string $login,
        #[Autowire(env: 'APP_DEV_API_TOKEN')]
        #[\SensitiveParameter]
        private readonly string $token,
        #[Autowire(env: 'APP_DEV_REPOSITORY')]
        private readonly string $directory,
    ) {
    }

    public function build(): void
    {
        $secret = ApiTokenSecret::tryFrom($this->token) ?? throw new \InvalidArgumentException('APP_DEV_API_TOKEN: the value does not match ApiTokenSecret::PATTERN.');

        if ('' === $this->login) {
            throw new \InvalidArgumentException('APP_LOCAL_SIGN_IN: the login of the local user is empty.');
        }

        $user = $this->users->provision($this->login);
        $this->tenantContext->set($user->getTenantId());
        $this->token($user, $secret);
        $this->repository();
    }

    /**
     * The token ID stays while the row is active, so an MCP session survives a second load.
     */
    private function token(User $user, ApiTokenSecret $secret): void
    {
        $existing = $this->tokens->findOneBy(['tokenHash' => $secret->hash()]);

        if (null !== $existing && ApiTokenState::Active === $existing->state($this->clock->now())) {
            return;
        }

        if (null !== $existing) {
            // The unit of work inserts before it deletes, and the hash is unique.
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        ApiTokenFactory::createOne(['user' => $user, 'name' => self::TOKEN_NAME, 'secret' => $secret]);
    }

    private function repository(): void
    {
        try {
            $this->connectLocalRepository->connect($this->directory);
        } catch (InvalidInput|NotFound $skipped) {
            $this->logger->warning('Skipped the repository APP_DEV_REPOSITORY "{directory}": {reason}', ['directory' => $this->directory, 'reason' => $skipped->getMessage()]);
        }
    }
}
