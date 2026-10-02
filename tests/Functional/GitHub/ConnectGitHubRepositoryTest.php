<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Domain\Repository\RepositoryName;
use App\Entity\Repository;
use App\Entity\User;
use App\Message\CloneRepositoryJob;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Envelope;

final class ConnectGitHubRepositoryTest extends WebTestCase
{
    use ApiFixtures;
    use GitHubRows;
    use JsonApiAssertions;
    use QueuedMessages;
    use ResetDatabase;

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create();
        $this->client->loginUser($this->user, 'web');
    }

    #[TestDox('A candidate connects with 201')]
    public function testCandidateConnects(): void
    {
        self::storeCandidate($this->user->getTenantId());

        $response = self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'github', 'githubRepositoryId' => 812345]);

        $repository = self::assertApiResponse($response, 201, 'repository.schema.json');
        self::assertSame("/api/v1/repositories/{$repository['id']}", $response->headers->get('Location'));
        self::assertSame(
            [
                'source' => 'github',
                'name' => 'acme/billing',
                'github' => ['repositoryId' => 812345, 'installationId' => 5551, 'owner' => 'acme', 'private' => true, 'htmlUrl' => 'https://github.com/acme/billing'],
                'local' => null,
                'access' => 'active',
                'sync' => ['state' => 'cloning', 'lastFetchedAt' => null, 'lastError' => null, 'defaultBranchHead' => null],
            ],
            array_intersect_key($repository, array_flip(['source', 'name', 'github', 'local', 'access', 'sync'])),
        );
        self::assertSame([CloneRepositoryJob::class], array_values(array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, self::asyncTransport()->getSent())));
    }

    #[TestDox('An ID that is not a candidate gives 404')]
    #[DataProvider('notCandidates')]
    public function testIdThatIsNotACandidateGivesNotFound(bool $otherTenantHasIt): void
    {
        self::storeCandidate($this->user->getTenantId(), 812399, 'acme/shipping');

        if ($otherTenantHasIt) {
            self::storeCandidate(TenantFixtures::of(self::getContainer())->withLogin('mona')->withGitHubUser(2)->create()->getTenantId());
        }

        $response = self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'github', 'githubRepositoryId' => 812345]);

        self::assertApiError($response, 404, 'not_found');
        self::assertSame([], self::asyncTransport()->getSent());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function notCandidates(): iterable
    {
        yield 'unknown' => [false];
        yield "another tenant's candidate" => [true];
    }

    #[TestDox('A name that another repository holds in another case gives 422')]
    public function testNameCollisionInAnotherCaseGivesUnprocessable(): void
    {
        self::storeCandidate($this->user->getTenantId());
        self::storeRow(Repository::gitHub($this->user->getTenantId(), RepositoryName::github('ACME/Billing'), 700, 5551, true, Clock::get()->now()));

        $response = self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'github', 'githubRepositoryId' => 812345]);

        self::assertSame(
            ['violations' => [['field' => 'githubRepositoryId', 'message' => 'Another connected repository has the name acme/billing.']]],
            self::assertApiError($response, 422, 'invalid_input')['details'],
        );
    }
}
