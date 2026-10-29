<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

/**
 * A second connection holds the draft lock of the user in the review, as a running submit does. `lock_timeout` ends
 * the wait of the write.
 */
final class DraftLockTest extends WebTestCase
{
    use ApiFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox('An edit, a delete, and a close of the review wait for a submit that holds the draft lock')]
    #[DataProvider('draftWrites')]
    public function testDraftWritesWaitForASubmit(string $method, string $path): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $client = static::createClient();
        $user = self::signIn($client);
        $reviewId = (string) self::startReviewThroughApi($client, self::connectAndClone($client))['id'];
        $draft = self::createDraft($client, $reviewId);
        // The request then runs on the connection that has the lock timeout.
        $client->disableReboot();
        $submitting = DriverManager::getConnection(self::getContainer()->get(Connection::class)->getParams());
        $draftLock = new LockFactory(new DoctrineDbalPostgreSqlStore($submitting))->createLock("drafts:{$reviewId}:{$user->getId()}", ttl: null);
        self::assertTrue($draftLock->acquire());
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("SET lock_timeout = '200ms'");

        try {
            $response = self::apiRequest($client, $method, strtr($path, ['{reviewId}' => $reviewId, '{draftId}' => (string) $draft['id']]), 'PATCH' === $method ? ['body' => 'Edited while the submit runs.'] : null);
        } finally {
            $connection->executeStatement('RESET lock_timeout');
            $draftLock->release();
            $submitting->close();
        }

        self::assertSame(500, $response->getStatusCode(), 'The write waited for the lock until the lock timeout.');
        self::assertSame([$draft], self::listDrafts($client, $reviewId));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function draftWrites(): iterable
    {
        yield 'edit' => ['PATCH', '/api/v1/drafts/{draftId}'];
        yield 'delete' => ['DELETE', '/api/v1/drafts/{draftId}'];
        yield 'close' => ['POST', '/api/v1/reviews/{reviewId}/close'];
    }
}
