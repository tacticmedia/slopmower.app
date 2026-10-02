<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Error\NotFound;
use App\Domain\Identity\TenantId;
use App\Entity\Repository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Ulid;

final class ConnectRejectsPathTricksTest extends KernelTestCase
{
    use EnvironmentVariables;
    use InvalidInputAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $this->tenantId = self::enterNewTenant();
    }

    #[TestDox('../x, a/b, and absolute paths give invalid_input and write nothing to disk')]
    public function testPathTricksAreInvalidInput(): void
    {
        foreach (['../billing', '..', 'billing/.git', '/etc', "{$this->localRepositoriesDirectory}/billing"] as $directory) {
            self::assertInvalidInput(['directory'], static fn (): Repository => self::connect($directory));
        }

        $this->assertNothingWritten();
    }

    #[TestDox('A valid name that is not listed gives not_found and writes nothing to disk')]
    public function testUnlistedNameIsNotFound(): void
    {
        new Filesystem()->mkdir("{$this->localRepositoriesDirectory}/plain");

        // BILLING resolves on a case-insensitive mount, such as the bind mount of a macOS host.
        foreach (['missing', 'plain', 'BILLING'] as $directory) {
            try {
                self::connect($directory);
                self::fail("{$directory} was accepted.");
            } catch (NotFound) {
            }
        }

        $this->assertNothingWritten();
    }

    private function assertNothingWritten(): void
    {
        $repositoryRows = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM repository WHERE tenant_id = ?',
            [Ulid::fromString($this->tenantId->toString())->toRfc4122()],
        );

        self::assertSame(0, $repositoryRows);
        self::assertSame([], self::asyncTransport()->getSent());
        self::assertSame(['.', '..'], scandir($this->tenantDataDirectory));
    }
}
