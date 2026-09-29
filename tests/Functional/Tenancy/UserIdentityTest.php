<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserIdentityTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('Two GitHub users can hold the same login')]
    public function testTwoGitHubUsersCanHoldTheSameLogin(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $fixtures->gitHubUser('octocat', 1);
        $fixtures->gitHubUser('octocat', 2);

        $users = self::getContainer()->get(TenantFilter::class)->suspend(
            static fn (): array => self::getContainer()->get(UserRepository::class)->findBy(['login' => 'octocat'], ['githubUserId' => 'ASC']),
        );

        self::assertSame([1, 2], array_map(static fn (User $user): ?int => $user->getGithubUserId(), $users));
    }

    #[TestDox('Two local users cannot hold the same login')]
    public function testTwoLocalUsersCannotHoldTheSameLogin(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $fixtures->localUser('jan');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_app_user_local_login');

        $fixtures->localUser('jan');
    }

    #[TestDox('Two users cannot hold the same GitHub user ID')]
    public function testTwoUsersCannotHoldTheSameGitHubUserId(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $fixtures->gitHubUser('octocat', 1);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('(github_user_id)=(1)');

        $fixtures->gitHubUser('renamed', 1);
    }
}
