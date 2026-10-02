<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds a tenant with its one user.
 */
final class TenantFixtures
{
    private string $login = 'jan';
    private ?int $githubUserId = null;

    private function __construct(private readonly ObjectManager $objectManager)
    {
    }

    public static function of(ContainerInterface $container): self
    {
        return new self($container->get('doctrine')->getManager());
    }

    public function withLogin(string $login): self
    {
        $fixtures = clone $this;
        $fixtures->login = $login;

        return $fixtures;
    }

    public function withGitHubUser(int $githubUserId): self
    {
        $fixtures = clone $this;
        $fixtures->githubUserId = $githubUserId;

        return $fixtures;
    }

    /**
     * Clears the object manager afterwards, so later reads go through the SQL filter, not the identity map.
     */
    public function create(): User
    {
        $now = Clock::get()->now();
        $tenant = new Tenant(TenantId::generate(), $now);
        $user = null === $this->githubUserId
            ? User::local($tenant->getId(), $this->login, $now)
            : User::gitHub($tenant->getId(), $this->githubUserId, $this->login, null, null, $now);

        $this->objectManager->persist($tenant);
        $this->objectManager->persist($user);
        $this->objectManager->flush();
        $this->objectManager->clear();

        return $user;
    }
}
