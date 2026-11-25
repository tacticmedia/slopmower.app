<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Identity\ProjectId;
use App\Domain\Issue\ApprovalMode;
use App\Entity\Project;
use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2 and P3 of docs/specs/rest-api-issues.md. The signed-in user creates the project BILL and sets its repositories.
 */
final class ProjectApiTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use McpAssertions;
    use ResetDatabase;

    private const string PROJECT = 'project.schema.json';
    private const string PROJECTS = '/api/v1/projects';
    private const array BILLING = ['key' => 'BILL', 'name' => 'Billing', 'approvalMode' => 'user'];
    private const array BILLING_PROJECT = self::BILLING + ['approvalGuidelines' => null];
    private const string GUIDELINES = "- A change of an API: `user`.\n- A fix of a typo: `self`.";

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
    }

    #[TestDox('P2 creates a project without a repository, with the trimmed name, and answers the path of P3 as Location')]
    public function testCreate(): void
    {
        $response = self::apiRequest($this->client, 'POST', self::PROJECTS, ['name' => '  Billing '] + self::BILLING);

        $created = self::assertApiResponse($response, 201, self::PROJECT);
        self::assertSame(self::BILLING_PROJECT + ['repositories' => []], $created);
        self::assertSame('/api/v1/projects/BILL', $response->headers->get('Location'));
        self::assertSame([$created], $this->projects());
    }

    #[TestDox('P2 refuses a second key and a wrong key with 422 on key')]
    public function testKeyIsRefused(): void
    {
        self::storeProject($this->user->getTenantId(), 'BILL');

        $second = self::assertApiError(self::apiRequest($this->client, 'POST', self::PROJECTS, self::BILLING), 422, 'invalid_input');
        $lowerCase = self::assertApiError(self::apiRequest($this->client, 'POST', self::PROJECTS, ['key' => 'bill'] + self::BILLING), 422, 'invalid_input');

        self::assertSame([['field' => 'key', 'message' => 'The project BILL exists already.']], $second['details']['violations'] ?? null);
        self::assertSame([['field' => 'key', 'message' => 'Must have 2 to 10 upper-case letters.']], $lowerCase['details']['violations'] ?? null);
        self::assertSame(['Project BILL'], array_column($this->projects(), 'name'));
    }

    /**
     * The row of the other create appears after the check of the key, as its commit does in a race.
     */
    #[TestDox('P2 answers 422 on key when a concurrent create took the key after the check')]
    public function testConcurrentCreateIsRefused(): void
    {
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $tenantId = $this->user->getTenantId()->toRfc4122();
        $container->get(EntityManagerInterface::class)->getEventManager()->addEventListener(Events::prePersist, new class($connection, $tenantId) {
            public function __construct(private readonly Connection $connection, private readonly string $tenantId)
            {
            }

            public function prePersist(PrePersistEventArgs $event): void
            {
                if ($event->getObject() instanceof Project) {
                    $this->connection->insert('project', ['id' => ProjectId::generate()->toRfc4122(), 'tenant_id' => $this->tenantId, 'key' => 'BILL', 'name' => 'Billing of the other tab', 'approval_mode' => 'self', 'next_issue_number' => 1, 'created_at' => '2026-10-01 09:00:00']);
                }
            }
        });

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', self::PROJECTS, self::BILLING), 422, 'invalid_input');

        self::assertSame([['field' => 'key', 'message' => 'The project BILL exists already.']], $error['details']['violations'] ?? null);
        self::assertSame(['Billing of the other tab'], array_column($this->projects(), 'name'));
    }

    #[TestDox('P3 changes the name and the mode, and sets the whole repository list: an ID twice counts once, and an empty list removes all')]
    public function testChangeNameModeAndRepositories(): void
    {
        $tenantId = $this->user->getTenantId();
        $alpha = self::storeRepository($tenantId, 'alpha')->getId()->toString();
        $beta = self::storeRepository($tenantId, 'beta')->getId()->toString();
        self::storeProject($tenantId, 'BILL');

        $renamed = $this->change('BILL', ['name' => ' Billing ', 'approvalMode' => 'user']);
        $both = $this->change('BILL', ['repositoryIds' => [$beta, $alpha, strtolower($beta)]]);
        $one = $this->change('BILL', ['repositoryIds' => [$beta]]);
        $kept = $this->change('BILL', []);
        $none = $this->change('BILL', ['repositoryIds' => []]);

        self::assertSame(self::BILLING_PROJECT + ['repositories' => []], $renamed);
        self::assertSame([['alpha', 'beta'], ['beta'], ['beta'], []], array_column([$both, $one, $kept, $none], 'repositories'));
        self::assertSame([$none], $this->projects());
    }

    #[TestDox('P3 saves the approval guidelines, keeps them without the field, removes them with whitespace only, and refuses more than 10,000 characters')]
    public function testApprovalGuidelines(): void
    {
        self::storeProject($this->user->getTenantId(), 'BILL');

        $saved = $this->change('BILL', ['approvalMode' => 'by_issue', 'approvalGuidelines' => self::GUIDELINES]);
        $kept = $this->change('BILL', ['name' => 'Billing']);
        $tooLong = self::assertApiError(self::apiRequest($this->client, 'PATCH', self::PROJECTS.'/BILL', ['approvalGuidelines' => str_repeat('a', 10001)]), 422, 'invalid_input');
        $removed = $this->change('BILL', ['approvalGuidelines' => " \n"]);

        self::assertSame([['by_issue', self::GUIDELINES], ['by_issue', self::GUIDELINES], ['by_issue', null]], array_map(static fn (array $project): array => [$project['approvalMode'], $project['approvalGuidelines']], [$saved, $kept, $removed]));
        self::assertSame(['approvalGuidelines'], array_column($tooLong['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('P3 answers 404 for an ID that the caller cannot see, then 422 on repositoryIds for a repository of another project, and changes nothing')]
    public function testRepositoryChecks(): void
    {
        $tenantId = $this->user->getTenantId();
        $alpha = self::storeRepository($tenantId, 'alpha');
        self::storeProject($tenantId, 'DOC', repository: $alpha);
        self::storeProject($tenantId, 'BILL');
        $hidden = self::storeRepository(TenantFixtures::of(self::getContainer())->withLogin('other')->create()->getTenantId(), 'hidden')->getId()->toString();
        $uri = self::PROJECTS.'/BILL';

        $malformed = self::assertApiError(self::apiRequest($this->client, 'PATCH', $uri, ['repositoryIds' => [$hidden, 'alpha']]), 422, 'invalid_input');
        self::assertApiError(self::apiRequest($this->client, 'PATCH', $uri, ['name' => 'Billing', 'repositoryIds' => [$alpha->getId()->toString(), $hidden]]), 404, 'not_found');
        $otherProject = self::assertApiError(self::apiRequest($this->client, 'PATCH', $uri, ['name' => 'Billing', 'repositoryIds' => [$alpha->getId()->toString()]]), 422, 'invalid_input');

        self::assertSame(['repositoryIds.1'], array_column($malformed['details']['violations'] ?? [], 'field'));
        self::assertSame([['field' => 'repositoryIds', 'message' => 'The repository alpha belongs to the project Project DOC.']], $otherProject['details']['violations'] ?? null);
        self::assertSame([['Project BILL', []], ['Project DOC', ['alpha']]], array_map(static fn (array $project): array => [$project['name'], $project['repositories']], $this->projects()));
    }

    #[TestDox('A token of one repository gets a project of P2 from list_projects after P3 gave the project that repository')]
    public function testRepositoryTokenSeesTheNewProject(): void
    {
        $alpha = self::storeRepository($this->user->getTenantId(), 'alpha');
        $scoped = new McpTestClient($this->client, self::storeToken($this->user, $alpha)[1]);
        self::assertApiResponse(self::apiRequest($this->client, 'POST', self::PROJECTS, self::BILLING), 201, self::PROJECT);

        $before = self::assertToolResult($scoped, $scoped->callTool('list_projects'), 'list_projects');
        $this->change('BILL', ['repositoryIds' => [$alpha->getId()->toString()]]);
        $after = self::assertToolResult($scoped, $scoped->callTool('list_projects'), 'list_projects');

        self::assertSame([[], [self::BILLING_PROJECT + ['repositories' => ['alpha']]]], [$before['items'], $after['items']]);
    }

    #[TestDox('P2 and P3 refuse a token with 403, so no agent sets the approval mode')]
    public function testATokenCannotWriteAProject(): void
    {
        self::storeProject($this->user->getTenantId(), 'BILL', ApprovalMode::User);
        [, $secret] = self::storeToken($this->user);

        self::assertApiError(self::bearerRequest($this->client, $secret, 'POST', self::PROJECTS, ['key' => 'DOC'] + self::BILLING), 403, 'forbidden');
        self::assertApiError(self::bearerRequest($this->client, $secret, 'PATCH', self::PROJECTS.'/BILL', ['approvalMode' => 'self']), 403, 'forbidden');

        self::assertSame([['BILL', 'user']], array_map(static fn (array $project): array => [$project['key'], $project['approvalMode']], $this->projects()));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> The project of P3
     */
    private function change(string $key, array $body): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'PATCH', self::PROJECTS."/{$key}", $body), 200, self::PROJECT);
    }

    /**
     * @return list<array<string, mixed>> The projects of P1
     */
    private function projects(): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', self::PROJECTS), 200, 'lists.schema.json#/$defs/ProjectPage')['items'];
    }
}
