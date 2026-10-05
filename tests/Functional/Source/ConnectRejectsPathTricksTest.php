<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Identity\TenantId;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

final class ConnectRejectsPathTricksTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $this->client = static::createClient();
        $this->tenantId = self::signIn($this->client)->getTenantId();
    }

    #[TestDox('../x, a/b, and absolute paths give invalid_input and write nothing to disk')]
    public function testPathTricksAreInvalidInput(): void
    {
        foreach (['../billing', '..', 'billing/.git', '/etc', "{$this->localRepositoriesDirectory}/billing"] as $directory) {
            $error = self::assertApiError($this->connectLocal($directory), 422, 'invalid_input');

            self::assertSame(['directory'], array_column($error['details']['violations'] ?? [], 'field'), $directory);
            $this->assertNothingWritten();
        }
    }

    #[TestDox('A valid name that is not listed gives not_found and writes nothing to disk')]
    public function testUnlistedNameIsNotFound(): void
    {
        new Filesystem()->mkdir("{$this->localRepositoriesDirectory}/plain");

        // BILLING resolves on a case-insensitive mount, such as the bind mount of a macOS host.
        foreach (['missing', 'plain', 'BILLING'] as $directory) {
            self::assertApiError($this->connectLocal($directory), 404, 'not_found');
            $this->assertNothingWritten();
        }
    }

    private function connectLocal(string $directory): Response
    {
        return self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => $directory]);
    }

    private function assertNothingWritten(): void
    {
        $repositoryRows = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM repository WHERE tenant_id = ?',
            [$this->tenantId->toRfc4122()],
        );

        self::assertSame(0, $repositoryRows);
        self::assertSame([], self::asyncTransport()->getSent());
        self::assertSame(['.', '..'], scandir($this->tenantDataDirectory));
    }
}
