<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Git\TenantDataPaths;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantDataPathsTest extends KernelTestCase
{
    private const string TENANT_ID = '01K5Z3T3G8Q6M4N2P0R8S6V4X2';
    private const string REPOSITORY_ID = '01K5Z3T3G8Q6M4N2P0R8S6V4X3';

    #[TestDox('Builds the clone path from the tenant ID and the repository ID')]
    public function testBuildsTheClonePathFromTheIds(): void
    {
        $paths = new TenantDataPaths('/srv/tenants/', '/app');

        self::assertSame('/srv/tenants', $paths->root());
        self::assertSame('/srv/tenants/'.self::TENANT_ID, $paths->tenantDirectory(TenantId::fromString(self::TENANT_ID)));
        self::assertSame(
            '/srv/tenants/'.self::TENANT_ID.'/repositories/'.self::REPOSITORY_ID.'.git',
            $paths->cloneDirectory(TenantId::fromString(self::TENANT_ID), RepositoryId::fromString(self::REPOSITORY_ID)),
        );
    }

    #[TestDox('Resolves a relative root against the project directory')]
    public function testResolvesARelativeRootAgainstTheProjectDirectory(): void
    {
        $projectDirectory = '/not-the-working-directory/project';
        self::assertNotSame($projectDirectory, getcwd());

        $paths = new TenantDataPaths('./var/cache/../test/tenants', $projectDirectory);

        self::assertSame('/not-the-working-directory/project/var/test/tenants', $paths->root());
        self::assertSame(
            '/not-the-working-directory/project/var/test/tenants/'.self::TENANT_ID.'/repositories/'.self::REPOSITORY_ID.'.git',
            $paths->cloneDirectory(TenantId::fromString(self::TENANT_ID), RepositoryId::fromString(self::REPOSITORY_ID)),
        );
    }

    #[TestDox('Refuses an empty root')]
    public function testRefusesAnEmptyRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TenantDataPaths(' ', '/app');
    }
}
