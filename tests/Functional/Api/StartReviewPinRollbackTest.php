<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Event\RepositoryFetched;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Entity\Review;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StartReviewPinRollbackTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox('When the review.started transaction fails, no pin remains')]
    public function testFailedTransactionLeavesNoPin(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $client = static::createClient();
        // One container for all requests, so the listener below sees the fetch of the start request.
        $client->disableReboot();
        $user = self::signIn($client);
        $repositoryId = self::connectAndClone($client);
        // The access is removed after the fetch and before the transaction, as a GitHub webhook would do.
        self::getContainer()->get('event_dispatcher')->addListener(RepositoryFetched::class, static function (): void {
            self::getContainer()->get(EntityManagerInterface::class)
                ->createQuery(sprintf('UPDATE %s repository SET repository.access = :removed, repository.syncState = :unavailable', Repository::class))
                ->setParameter('removed', RepositoryAccess::Removed)
                ->setParameter('unavailable', SyncState::Unavailable)
                ->execute();
        });

        $error = self::assertApiError(self::apiRequest($client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => 'main']), 409, 'repository_unavailable');

        self::assertSame(['repositoryId' => $repositoryId, 'reason' => 'removed'], $error['details']);
        self::assertSame([], self::pins($user, $repositoryId));
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM review'), 'No '.Review::class.' row.');
    }
}
