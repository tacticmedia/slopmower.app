<?php

declare(strict_types=1);

namespace App\Tests\Functional\Index;

use App\Application\ReviewCommands;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Message\IndexReview;
use App\Message\QueueStalledIndexesJob;
use App\Messenger\TenantStamp;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * D95. Each review starts with its index job queued, which the test drops, as a crash after the commit would.
 */
final class StalledIndexTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    #[TestDox('The hourly job queues the index job of each open review whose index is pending for more than 1 hour')]
    public function testQueuesStalledIndexes(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(['src/A.php' => "<?php\n\nfunction a(): void\n{\n}\n"]);
        $tenantId = self::enterNewTenant();
        $repository = self::connect();
        self::runQueuedMessages();
        $start = static fn (): ReviewId => self::getContainer()->get(ReviewCommands::class)->start($repository->getId(), RefName::fromString('main'), null, RequestScenario::user());
        [$stalled, , $closed] = [$start(), $start(), $start()];
        self::asyncTransport()->reset();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE review SET created_at = created_at - interval '61 minutes' WHERE id IN (?, ?)", [$stalled->toRfc4122(), $closed->toRfc4122()]);
        $connection->executeStatement("UPDATE review SET state = 'closed' WHERE id = ?", [$closed->toRfc4122()]);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new QueueStalledIndexesJob(), [new ReceivedStamp('scheduler_default')]);

        self::assertEquals(
            [[new IndexReview($stalled), $tenantId]],
            array_map(static fn (Envelope $envelope): array => [$envelope->getMessage(), $envelope->last(TenantStamp::class)?->tenantId], [...self::asyncTransport()->getSent()]),
        );
    }
}
