<?php

declare(strict_types=1);

namespace App\Tests\Functional\Live;

use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryUpdateTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use PublishedUpdates;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    #[TestDox('A fetch publishes a repository update with the repository ID')]
    public function testFetchPublishesARepositoryUpdate(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $tenantId = self::enterNewTenant();
        $repositoryId = self::connect()->getId();
        self::runQueuedMessages();
        $before = \count(self::publishedUpdates());

        self::fetchRepository()->fetchNow($repositoryId);

        $updates = \array_slice(self::publishedUpdates(), $before);
        self::assertCount(2, $updates, 'The fetch starts and ends.');
        $expected = ['type' => 'repository', 'repositoryId' => $repositoryId->toString()];

        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate());
            self::assertSame(["http://localhost/tenants/{$tenantId}/repositories"], $update->getTopics());
            self::assertSame($expected, self::updateData($update));
            self::assertSame([], SchemaValidator::errors($update->getData(), 'live-update.schema.json'));
        }
    }
}
