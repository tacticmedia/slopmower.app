<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Message\GitHubPushJob;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class DuplicateDeliveryTest extends WebTestCase
{
    use ClonedGitHubRepositories;
    use EnvironmentVariables;
    use GitHubRows;
    use GitHubWebhooks;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string DELIVERY_ID = '72d3162e-cc78-11e3-81ab-4c9367dc0958';

    #[TestDox('The same delivery ID runs once')]
    public function testSameDeliveryRunsOnce(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        self::clonedGitHubRepository(1);

        self::deliverFixture($client, 'push', deliveryId: self::DELIVERY_ID);
        self::runQueuedMessages();
        self::assertSame(1, self::sentPushJobs());

        self::assertSame(202, self::deliverFixture($client, 'push', deliveryId: self::DELIVERY_ID)->getStatusCode());
        self::runQueuedMessages();
        self::assertSame(0, self::sentPushJobs());
        self::assertSame([self::DELIVERY_ID], self::deliveryIds());
    }

    #[TestDox('A consumer run that fails leaves no delivery row')]
    public function testFailedRunLeavesNoDeliveryRow(): void
    {
        $client = static::createClient();
        self::deliverFixture($client, 'push', ['repository' => ['id' => 'not an ID']], self::DELIVERY_ID);

        try {
            self::runQueuedMessages();
            self::fail('The consumer did not fail.');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failed->getPrevious());
        }

        self::assertSame([], self::deliveryIds());
    }

    private static function sentPushJobs(): int
    {
        return \count(array_filter(self::asyncTransport()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof GitHubPushJob));
    }

    /**
     * @return list<mixed>
     */
    private static function deliveryIds(): array
    {
        return self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT id FROM webhook_delivery');
    }
}
