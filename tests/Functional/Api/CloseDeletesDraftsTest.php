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
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CloseDeletesDraftsTest extends WebTestCase
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

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
    }

    #[TestDox("Closing a review deletes the caller's drafts of that review")]
    public function testCloseDeletesCallersDraftsOfThatReview(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        $repositoryId = self::connectAndClone($client);
        $closed = (string) self::startReviewThroughApi($client, $repositoryId)['id'];
        $open = (string) self::startReviewThroughApi($client, $repositoryId)['id'];
        self::createDraft($client, $closed);
        $otherReviewDraft = self::createDraft($client, $open);
        $otherUser = self::secondUser($user);
        $client->loginUser($otherUser, 'web');
        $otherUsersDraft = self::createDraft($client, $closed, body: 'A draft of another user.');
        $client->loginUser($user, 'web');

        self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$closed}/close"), 200, 'review.schema.json');

        self::assertSame([], self::listDrafts($client, $closed));
        self::assertSame([$otherReviewDraft], self::listDrafts($client, $open));
        $client->loginUser($otherUser, 'web');
        self::assertSame([$otherUsersDraft], self::listDrafts($client, $closed));
    }

    #[TestDox('A projection rebuild keeps the current drafts')]
    public function testProjectionRebuildKeepsDrafts(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        $reviewId = self::startCodeReview($client);
        $requestId = (string) self::openRequest($client, $reviewId)['id'];
        self::createDraft($client, $reviewId);
        self::createReplyDraft($client, $reviewId, $requestId);
        $before = self::listDrafts($client, $reviewId);

        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:projections:rebuild'));
        $tester->execute(['--tenant' => $user->getTenantId()->toString()]);

        $tester->assertCommandIsSuccessful();
        self::assertCount(2, $before);
        self::assertSame($before, self::listDrafts($client, $reviewId));
    }
}
