<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThreadTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private KernelBrowser $client;

    private User $user;

    private string $reviewId;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        $this->requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
    }

    #[TestDox('Comments appear in order with actor user')]
    public function testCommentsAppearInOrderWithUserActor(): void
    {
        $first = self::requestCommand($this->client, $this->requestId, 'comments', ['body' => 'Keep the old method as deprecated.']);
        self::requestCommand($this->client, $this->requestId, 'comments', ['body' => 'Also cover the shipping totals.', 'question' => null]);

        $thread = self::readRequest($this->client, $this->requestId)['thread'];
        $seqs = array_column($thread, 'seq');
        $ascending = array_values(array_unique($seqs));
        sort($ascending);

        self::assertSame(['request.opened', 'request.commented', 'request.commented'], array_column($thread, 'type'));
        self::assertSame(['Use `Money` instead of `float` for totals.', 'Keep the old method as deprecated.', 'Also cover the shipping totals.'], array_column($thread, 'body'));
        self::assertSame(['user'], array_values(array_unique(array_column(array_column($thread, 'actor'), 'kind'))));
        self::assertSame($ascending, $seqs);
        self::assertSame(['request.commented', $this->requestId, 'open', $thread[1]['commentId']], [$first['events'][0]['type'], $first['events'][0]['requestId'], $first['events'][0]['statusAfter'], $first['events'][0]['commentId']]);
        self::assertSame(1, $first['request']['commentCount']);
    }

    #[TestDox('The thread and the commits hold a commit linked to this and another request')]
    public function testThreadAndCommitsHoldSharedCommitLink(): void
    {
        $other = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $requestIds = [RequestId::fromString($this->requestId), RequestId::fromString($other)];
        self::asAgent($this->user, static function (RequestCommands $commands, Actor $agent) use ($requestIds): void {
            array_map(static fn (RequestId $requestId): array => $commands->claim(new Claim($requestId), $agent), $requestIds);
            $commands->linkCommits(new LinkCommits(CommitSha::fromString(self::COMMIT), $requestIds, 'One refactoring covers both.'), $agent, []);
        });

        $request = self::readRequest($this->client, $this->requestId);

        self::assertSame(
            [['request.opened', 'open', null], ['request.claimed', 'in_progress', null], ['commit.linked', 'in_progress', self::COMMIT]],
            array_map(static fn (array $entry): array => [$entry['type'], $entry['statusAfter'], $entry['commitSha']], $request['thread']),
        );
        $expected = ['commitSha' => self::COMMIT, 'state' => 'pending', 'note' => 'One refactoring covers both.', 'foundAt' => null, 'otherRequestIds' => [$other]];
        self::assertSame([$expected], array_map(static fn (array $link): array => array_intersect_key($link, $expected), $request['commits']));
        self::assertSame([1, 1], [$request['commitCount'], $request['pendingCommitCount']]);
    }

    #[TestDox('A user comment with question true gives 422')]
    public function testUserQuestionGivesInvalidInput(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$this->requestId}/comments", ['body' => 'Why?', 'question' => true]), 422, 'invalid_input');

        self::assertSame([['field' => 'question', 'message' => 'Only an agent asks a question.']], $error['details']['violations'] ?? null);
        self::assertCount(1, self::readRequest($this->client, $this->requestId)['thread']);
    }
}
