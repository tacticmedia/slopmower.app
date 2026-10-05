<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Entity\Repository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantIsolatedClonePathTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    #[TestDox('Repositories of two tenants on one directory clone to two tenant paths')]
    public function testTwoTenantsGetTwoClones(): void
    {
        $this->useTemporaryLocalSources();
        $head = $this->newSource()->revParse('HEAD');

        $first = $this->connectAndCloneAs('ann');
        $second = $this->connectAndCloneAs('bob');

        self::assertNotSame(self::clonePath($first), self::clonePath($second));
        self::assertSame("{$head}\n", self::cloneGit($first, 'rev-parse', 'refs/heads/main'));
        self::assertSame("{$head}\n", self::cloneGit($second, 'rev-parse', 'refs/heads/main'));
    }

    private function connectAndCloneAs(string $login): Repository
    {
        self::enterNewTenant($login);
        $repositoryId = self::connect()->getId();
        self::runQueuedMessages();

        return self::freshRepository($repositoryId);
    }
}
