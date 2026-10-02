<?php

declare(strict_types=1);

namespace App\Tests\Functional\Index;

use App\Application\ReviewCommands;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\IndexState;
use App\Entity\Review;
use App\Message\IndexReview;
use App\Repository\ReviewRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;

final class RebuildIndexCommandTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private ReviewId $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(['src/A.php' => "<?php\n\nfunction a(): void\n{\n}\n"]);
        self::enterNewTenant();
        $repository = self::connect();
        self::runQueuedMessages();
        $this->reviewId = self::getContainer()->get(ReviewCommands::class)->start($repository->getId(), RefName::fromString('main'), null, RequestScenario::user());
        self::runQueuedMessages();
    }

    #[TestDox('--reparse resets the index status, deletes the parsed blobs, and queues the job')]
    public function testReparseQueuesTheJobAfresh(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        // Without --reparse the blob row would keep the job from parsing the blob again.
        $connection->executeStatement('DELETE FROM symbol');

        self::rebuild($this->review()->getTenantId()->toString(), $this->reviewId->toString(), true)->assertCommandIsSuccessful();
        $queued = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), [...self::asyncTransport()->get()]);
        $pending = $this->review()->getIndexState();
        self::runQueuedMessages();

        self::assertEquals([new IndexReview($this->reviewId)], $queued);
        self::assertSame(IndexState::Pending, $pending);
        self::assertSame(['a()'], $connection->fetchFirstColumn('SELECT fqn FROM symbol'));
        self::assertSame(IndexState::Ready, $this->review()->getIndexState());
    }

    #[TestDox('A review of another tenant is not found')]
    public function testReviewOfAnotherTenantIsNotFound(): void
    {
        $other = TenantFixtures::of(self::getContainer())->withLogin('octocat')->create()->getTenantId();

        $tester = self::rebuild($other->toString(), $this->reviewId->toString(), false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("The tenant {$other} has no review", $tester->getDisplay());
    }

    private function review(): Review
    {
        return self::getContainer()->get(ReviewRepository::class)->findFresh($this->reviewId) ?? self::fail('No review.');
    }

    private static function rebuild(string $tenantId, string $reviewId, bool $reparse): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:index:rebuild'));
        $tester->execute(['tenant-id' => $tenantId, 'review-id' => $reviewId, '--reparse' => $reparse]);

        return $tester;
    }
}
