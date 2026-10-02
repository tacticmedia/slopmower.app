<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Application\EventLogQuery;
use App\Domain\Actor\ActorKind;
use App\Domain\Event\EventType;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Git\GitRunner;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Message\FetchRepositoryJob;
use App\Messenger\TenantStamp;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\EventStore\CommandFixtures;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PushWebhookTest extends WebTestCase
{
    use ClonedGitHubRepositories;
    use CommandFixtures;
    use EnvironmentVariables;
    use GitHubRows;
    use GitHubWebhooks;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('Two tenants with the same GitHub repository each get one fetch')]
    public function testTwoTenantsEachGetOneFetch(): void
    {
        $this->useLocalGitHub();
        $source = $this->gitHubSource();
        $client = static::createClient();
        $first = self::clonedGitHubRepository(1);
        $second = self::clonedGitHubRepository(2);
        $head = $source->commit(['CHANGELOG.md' => "Fixed\n"]);
        $source->git('push', '--quiet', 'origin', 'main');

        self::assertSame(202, self::deliverFixture($client, 'push')->getStatusCode());
        self::runQueuedMessages();

        $fetched = [];

        foreach (self::asyncTransport()->getSent() as $envelope) {
            $job = $envelope->getMessage();

            if ($job instanceof FetchRepositoryJob) {
                $fetched[] = [(string) $job->repositoryId, (string) $envelope->last(TenantStamp::class)?->tenantId];
            }
        }

        self::assertEqualsCanonicalizing([[(string) $first->getId(), (string) $first->getTenantId()], [(string) $second->getId(), (string) $second->getTenantId()]], $fetched);

        foreach ([$first, $second] as $repository) {
            self::assertSame($head, self::storedRepository($repository)->getDefaultBranchHead()?->toString());
        }
    }

    #[TestDox('A pending link resolves with commit.found and a pin')]
    public function testPendingLinkResolves(): void
    {
        $this->useLocalGitHub();
        $source = $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);
        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());
        $reviewId = self::startReview($repository->getId());
        $requestId = self::openRequest($reviewId);
        self::requestCommands()->claim(new Claim($requestId), RequestScenario::claimingAgent());
        $fix = $source->commit(['src/Fix.php' => "<?php\n"], 'Round totals');
        self::requestCommands()->linkCommits(new LinkCommits(CommitSha::fromString($fix), [$requestId]), RequestScenario::claimingAgent(), []);
        $source->git('push', '--quiet', 'origin', 'main');

        self::deliverFixture($client, 'push');
        self::runQueuedMessages();

        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());
        $found = array_values(array_filter(
            self::getContainer()->get(EventLogQuery::class)->after($reviewId, 0, 500),
            static fn (RecordedEvent $event): bool => EventType::CommitFound === $event->type,
        ));
        self::assertCount(1, $found);
        self::assertSame(ActorKind::System, $found[0]->actor->kind);
        $clonePath = self::getContainer()->get(TenantDataPaths::class)->clonePath($repository->getTenantId(), $repository->getId());
        self::assertSame($fix, trim(new GitRunner(new NullLogger())->run($clonePath, ['rev-parse', '--verify', PinRefs::COMMITS.$fix])));
    }
}
