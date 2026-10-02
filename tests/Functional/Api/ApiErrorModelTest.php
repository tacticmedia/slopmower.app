<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Identity\RepositoryId;
use App\Http\ApiBodySizeListener;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\VarDumper\Cloner\Data;

final class ApiErrorModelTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[TestDox('An anonymous call to a route with a path ID gets 401, not 500')]
    public function testAnonymousPathIdGetsUnauthorized(): void
    {
        $response = self::apiRequest($this->client, 'GET', '/api/v1/repositories/'.RepositoryId::generate());

        self::assertApiError($response, 401, 'unauthorized');
        self::assertSame('Bearer realm="holistic-review"', $response->headers->get('WWW-Authenticate'));
    }

    #[TestDox('Bad JSON gives 400 invalid_input')]
    public function testBadJsonGivesBadRequest(): void
    {
        self::signIn($this->client);

        $this->client->request('POST', '/api/v1/repositories', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], content: '{"source":');

        $error = self::assertApiError($this->client->getResponse(), 400, 'invalid_input');
        self::assertSame(['violations' => [['field' => '', 'message' => 'The body is not valid JSON.']]], $error['details']);
    }

    #[TestDox('A body that is not JSON gives 415, and a body above 1 MiB gives 413')]
    public function testBodyTypeAndSize(): void
    {
        self::signIn($this->client);

        $this->client->request('POST', '/api/v1/repositories', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], content: 'billing');
        self::assertNull(self::assertApiError($this->client->getResponse(), 415, 'invalid_input')['details']);

        $tooLarge = ['source' => 'local', 'directory' => str_repeat('a', ApiBodySizeListener::MAX_BODY_BYTES)];
        self::assertNull(self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/repositories', $tooLarge), 413, 'invalid_input')['details']);
    }

    #[TestDox('A wrong method gives 405 with an Allow header')]
    public function testWrongMethodGivesMethodNotAllowed(): void
    {
        $response = self::apiRequest($this->client, 'PATCH', '/api/v1/repositories');

        $error = self::assertApiError($response, 405, 'invalid_input');
        self::assertSame('GET, POST', $response->headers->get('Allow'));
        self::assertSame('Method PATCH is not allowed. Allowed methods: GET, POST.', $error['message']);
        self::assertNull($error['details']);
    }

    #[TestDox('An unknown route under /api/v1 gives 404 not_found')]
    public function testUnknownRouteGivesNotFound(): void
    {
        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/no-such-route'), 404, 'not_found');
    }

    #[TestDox('A validation failure gives 422 with violations')]
    public function testValidationFailureGivesViolations(): void
    {
        self::signIn($this->client);

        $missingDirectory = self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local']), 422, 'invalid_input');
        $wrongType = self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => ['billing']]), 422, 'invalid_input');

        self::assertSame([['field' => 'directory', 'message' => 'This value should not be null.']], $missingDirectory['details']['violations'] ?? null);
        self::assertSame(['directory'], array_column($wrongType['details']['violations'] ?? [], 'field'), 'A body value of the wrong type is 422, not 400.');
    }

    #[TestDox('An unknown ID gives 404 not_found')]
    public function testUnknownIdGivesNotFound(): void
    {
        self::signIn($this->client);

        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/repositories/'.RepositoryId::generate()), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/repositories/not-a-ulid'), 404, 'not_found');
    }

    #[TestDox('A domain error gives its documented status and details')]
    public function testDomainErrorGivesStatusAndDetails(): void
    {
        $user = self::signIn($this->client);
        $repository = self::storeRepository($user->getTenantId(), 'billing');
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE repository SET access = 'removed', sync_state = 'unavailable' WHERE id = ?",
            [$repository->getId()->toString()],
            ['ulid'],
        );

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repository->getId()}/fetch"), 409, 'repository_unavailable');

        self::assertSame(['repositoryId' => $repository->getId()->toString(), 'reason' => 'removed'], $error['details']);
    }

    #[TestDox('An invisible ID with an invalid body gives 404, and without a CSRF proof gives 404')]
    public function testInvisibleIdComesFirst(): void
    {
        $otherTenantRepository = self::storeRepository(TenantFixtures::of(self::getContainer())->withLogin('other')->create()->getTenantId(), 'billing');
        self::signIn($this->client);
        $path = "/api/v1/repositories/{$otherTenantRepository->getId()}/fetch";

        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], content: str_repeat('x', ApiBodySizeListener::MAX_BODY_BYTES + 1));
        self::assertApiError($this->client->getResponse(), 404, 'not_found');

        self::assertApiError(self::apiRequest($this->client, 'POST', $path, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']), 404, 'not_found');
    }

    #[TestDox('A query value of the wrong type gives 400, and an out-of-range query value gives 422')]
    public function testQueryTypeAndRange(): void
    {
        self::signIn($this->client);

        $wrongType = self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/repositories?limit=many'), 400, 'invalid_input');
        $outOfRange = self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/repositories?limit=501'), 422, 'invalid_input');

        self::assertSame(['limit'], array_column($wrongType['details']['violations'] ?? [], 'field'));
        self::assertSame(['limit'], array_column($outOfRange['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('A query value that is not UTF-8 gives 422')]
    public function testNonUtf8QueryValueIsRefused(): void
    {
        self::signIn($this->client);

        $name = self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/repositories?name=%FF'), 422, 'invalid_input');
        $q = self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/repositories/candidates?q=%FF'), 422, 'invalid_input');

        self::assertSame(['name'], array_column($name['details']['violations'] ?? [], 'field'));
        self::assertSame(['q'], array_column($q['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('An unexpected exception gives 500 internal_error with a generic message')]
    public function testUnexpectedExceptionGivesInternalError(): void
    {
        $user = self::signIn($this->client);
        $repository = self::storeRepository($user->getTenantId(), 'billing');
        // A corrupt row: the name breaks the local name rules.
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE repository SET name = '../escape' WHERE id = ?", [$repository->getId()->toString()], ['ulid']);
        $this->client->enableProfiler();

        $response = self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repository->getId()}");

        $error = self::assertApiError($response, 500, 'internal_error');
        self::assertSame('An unexpected error occurred.', $error['message']);
        self::assertStringNotContainsString('escape', (string) $response->getContent());
        self::assertSame(['UnexpectedValueException'], self::criticalLogExceptionClasses());
    }

    /**
     * @return list<string>
     */
    private function criticalLogExceptionClasses(): array
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('logger');
        self::assertInstanceOf(LoggerDataCollector::class, $collector);
        $classes = [];

        foreach ($collector->getProcessedLogs() as $log) {
            $message = $log['message'] instanceof Data ? $log['message']->getValue() : $log['message'];

            if ('CRITICAL' === $log['priorityName'] && \is_string($message) && 1 === preg_match('/^Uncaught PHP Exception (\S+):/', $message, $match)) {
                $classes[] = $match[1];
            }
        }

        return $classes;
    }
}
