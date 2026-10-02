<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Entity\Tenant;
use App\Message\FetchRepositoryJob;
use App\Message\GitHubInstallationDeletedJob;
use App\Message\GitHubPushJob;
use App\Messenger\TenantStamp;
use App\Tenancy\TenantOwned;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;
use Symfony\Component\RemoteEvent\RemoteEvent;

final class WebhookTenantFilterTest extends WebTestCase
{
    use ClonedGitHubRepositories;
    use EnvironmentVariables;
    use GitHubRows;
    use GitHubWebhooks;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('Each job from the consumer runs under its own tenant')]
    public function testEachJobRunsUnderItsOwnTenant(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $first = self::clonedGitHubRepository(1);
        $second = self::clonedGitHubRepository(2);
        self::deliverFixture($client, 'push');

        self::runConsumer();

        $pushJobs = self::sentOf(GitHubPushJob::class);
        self::assertEqualsCanonicalizing([(string) $first->getTenantId(), (string) $second->getTenantId()], array_map(self::stampedTenant(...), $pushJobs));

        self::runQueuedMessages();

        // The middleware stamps the fetch with the tenant context of the job that requested it.
        $fetched = array_map(static function (Envelope $envelope): array {
            $job = $envelope->getMessage();
            \assert($job instanceof FetchRepositoryJob);

            return [(string) $job->repositoryId, self::stampedTenant($envelope)];
        }, self::sentOf(FetchRepositoryJob::class));
        self::assertEqualsCanonicalizing([[(string) $first->getId(), (string) $first->getTenantId()], [(string) $second->getId(), (string) $second->getTenantId()]], $fetched);
    }

    #[TestDox('The consumer writes no tenant row')]
    public function testConsumerWritesNoTenantRow(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        self::clonedGitHubRepository(1);
        self::clonedGitHubRepository(2);
        self::deliverFixture($client, 'installation.deleted');
        $before = self::tenantRows();

        self::runConsumer();

        self::assertNotSame([], self::sentOf(GitHubInstallationDeletedJob::class));
        self::assertSame($before, self::tenantRows());
    }

    #[TestDox('The consumer message passes the tenant middleware without a stamp')]
    public function testConsumerMessagePassesWithoutStamp(): void
    {
        static::createClient();
        $message = new ConsumeRemoteEventMessage('github', new RemoteEvent('github.ping', '72d3162e-cc78-11e3-81ab-4c9367dc0958', ['zen' => 'Keep it logically awesome.']));

        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($message, [new ReceivedStamp('async')]));

        self::assertNotNull($envelope->last(HandledStamp::class));
        self::assertSame(['72d3162e-cc78-11e3-81ab-4c9367dc0958'], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT id FROM webhook_delivery'));
    }

    /**
     * Handles only the queued ConsumeRemoteEventMessage, as the worker does.
     */
    private static function runConsumer(): void
    {
        $transport = self::asyncTransport();
        $envelopes = [...$transport->get()];
        self::assertCount(1, $envelopes);
        self::assertInstanceOf(ConsumeRemoteEventMessage::class, $envelopes[0]->getMessage());
        self::assertNull($envelopes[0]->last(TenantStamp::class));

        $transport->ack(self::getContainer()->get(MessageBusInterface::class)->dispatch($envelopes[0]->with(new ReceivedStamp('async'))));
    }

    /**
     * @param class-string $messageClass
     *
     * @return list<Envelope>
     */
    private static function sentOf(string $messageClass): array
    {
        return array_values(array_filter(self::asyncTransport()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $messageClass));
    }

    private static function stampedTenant(Envelope $envelope): string
    {
        return (string) ($envelope->last(TenantStamp::class) ?? self::fail('The job has no TenantStamp.'))->tenantId;
    }

    /**
     * Every row of every tenant table.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function tenantRows(): array
    {
        $connection = self::getContainer()->get(Connection::class);
        $rows = [];

        foreach (self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (Tenant::class === $metadata->getName() || $metadata->getReflectionClass()->implementsInterface(TenantOwned::class)) {
                $rows[$metadata->getTableName()] = $connection->fetchAllAssociative("SELECT * FROM {$metadata->getTableName()} ORDER BY 1");
            }
        }

        return $rows;
    }
}
