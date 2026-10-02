<?php

declare(strict_types=1);

namespace App\Tests\Functional\Index;

use App\Application\ReviewCommands;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Entity\Repository;
use App\Tests\Support\Clock\SteppingClock;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mercure\Update;

final class IndexLiveUpdateTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use LocalSources;
    use PublishedUpdates;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    #[TestDox('Index updates go out at most once per second, plus the final state')]
    public function testUpdatesAreThrottled(): void
    {
        $this->useTemporaryLocalSources();
        $files = [];

        for ($i = 1; $i <= 10; ++$i) {
            $files["src/Class{$i}.php"] = "<?php\n\nfinal class Class{$i}\n{\n}\n";
        }

        $this->newSource()->commit($files);
        $tenantId = self::enterNewTenant();
        $repository = self::connect();
        self::runQueuedMessages();
        self::mockTime('2026-10-02 08:00:00');
        $frozen = self::indexUpdates($tenantId, self::startReview($repository));
        // 11 entries, read 0.4 s apart: updates at the start, at 1.2 s, 2.4 s, and 3.6 s, and the final one at 4.8 s.
        Clock::set(new SteppingClock(0.4, '2026-10-02 09:00:00'));
        $moving = self::indexUpdates($tenantId, self::startReview($repository));

        self::assertCount(2, $frozen, 'The start and the final state, because no time passed.');
        self::assertCount(5, $moving);
    }

    private static function startReview(Repository $repository): ReviewId
    {
        return self::getContainer()->get(ReviewCommands::class)->start($repository->getId(), RefName::fromString('main'), null, RequestScenario::user());
    }

    /**
     * Runs the queued index job of the review.
     *
     * @return list<Update> The `index` updates of the job, each checked for its topic and its payload
     */
    private static function indexUpdates(TenantId $tenantId, ReviewId $reviewId): array
    {
        $before = count(self::publishedUpdates());
        self::runQueuedMessages();
        $updates = array_values(array_filter(array_slice(self::publishedUpdates(), $before), static fn (Update $update): bool => 'index' === (self::updateData($update)['type'] ?? null)));

        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate());
            self::assertSame(["http://localhost/tenants/{$tenantId}/reviews/{$reviewId}"], $update->getTopics());
            self::assertSame(['type' => 'index', 'reviewId' => $reviewId->toString()], self::updateData($update));
        }

        return $updates;
    }
}
