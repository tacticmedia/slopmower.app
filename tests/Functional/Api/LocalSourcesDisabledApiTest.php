<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocalSourcesDisabledApiTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->setEnvironmentVariable('APP_LOCAL_REPOSITORIES_DIR', '');
        $this->client = static::createClient();
        self::signIn($this->client);
    }

    #[TestDox('Candidates report localEnabled false and no local items')]
    public function testCandidatesReportDisabled(): void
    {
        $candidates = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories/candidates'), 200, 'lists.schema.json#/$defs/CandidateList');

        self::assertSame(['items' => [], 'truncated' => false, 'localEnabled' => false, 'githubListedAt' => null, 'githubConfigureUrl' => null], $candidates);
    }

    #[TestDox('Connecting a local repository gives 422 invalid_input')]
    public function testConnectGivesInvalidInput(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => 'billing']), 422, 'invalid_input');

        self::assertSame(['source'], array_column($error['details']['violations'] ?? [], 'field'));
    }
}
