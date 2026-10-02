<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

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
    public function testGitHubUsersCanShareALogin(): void
    {
        $octocat = TenantFixtures::of(self::getContainer())->withLogin('octocat');
        $octocat->withGitHubUser(1)->create();
        $octocat->withGitHubUser(2)->create();

        $count = self::getContainer()->get(TenantFilter::class)->suspend(
            static fn (): int => self::getContainer()->get(UserRepository::class)->count(['login' => 'octocat']),
        );
        self::assertSame(2, $count);
    }

    #[TestDox('Two local users cannot hold the same login')]
    public function testLocalUsersCannotShareALogin(): void
    {
        $jan = TenantFixtures::of(self::getContainer())->withLogin('jan');
        $jan->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $jan->create();
    }
}
