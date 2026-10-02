<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiCsrfTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use ResetDatabase;
    use TemporaryDirectories;

    private const array CONNECT = ['source' => 'local', 'directory' => 'billing'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource('billing');
        $this->client = static::createClient();
        self::signIn($this->client);
    }

    #[TestDox('A session POST without same-origin proof gets 403 forbidden')]
    public function testPostWithoutProofIsForbidden(): void
    {
        $crossSite = self::apiRequest($this->client, 'POST', '/api/v1/repositories', self::CONNECT, ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertApiError($crossSite, 403, 'forbidden');
    }

    #[TestDox('A session POST with Sec-Fetch-Site same-origin passes')]
    public function testSameOriginPostPasses(): void
    {
        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/repositories', self::CONNECT), 201, 'repository.schema.json');
    }
}
