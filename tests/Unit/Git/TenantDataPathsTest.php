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
    private const string TENANT_ID = '01K6H2V0J6QZ8W3N4M5P6R7S8T';

    private const string REPOSITORY_ID = '01K6H2V0J6QZ8W3N4M5P6R7S9V';

    #[TestDox('Builds the clone path from the tenant ID and the repository ID')]
    public function testBuildsClonePath(): void
    {
        $paths = new TenantDataPaths('/srv/tenants', '/app');

        self::assertSame(
            '/srv/tenants/'.self::TENANT_ID.'/repositories/'.self::REPOSITORY_ID.'.git',
            $paths->clonePath(TenantId::fromString(self::TENANT_ID), RepositoryId::fromString(self::REPOSITORY_ID)),
        );
    }

    #[TestDox('Resolves a relative root against the project directory')]
    public function testResolvesRelativeRoot(): void
    {
        $paths = new TenantDataPaths('var/test/tenants', '/srv/project');

        self::assertSame(
            '/srv/project/var/test/tenants/'.self::TENANT_ID.'/repositories/'.self::REPOSITORY_ID.'.git',
            $paths->clonePath(TenantId::fromString(self::TENANT_ID), RepositoryId::fromString(self::REPOSITORY_ID)),
        );
    }
}
