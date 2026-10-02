<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Reply;
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

/**
 * The user opened r1, r2, and r3. Each test starts at the cursor after them.
 */
final class UpdatesCursorTest extends WebTestCase
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

    private KernelBrowser $client;

    private User $user;

    private string $reviewId;

    /** @var array{RequestId, RequestId, RequestId} */
    private array $requests;

    private int $cursor;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        $open = fn (): RequestId => RequestId::fromString((string) self::openRequest($this->client, $this->reviewId)['id']);
        $this->requests = [$open(), $open(), $open()];
        $this->cursor = $this->updates('')['cursor'];
    }

    #[TestDox('Without a cursor, returns the requests that wait for the user with their new events')]
    public function testWithoutCursorReturnsRequestsThatWaitForUser(): void
    {
        [$r1, $r2] = $this->requests;
        self::asAgent($this->user, static function (RequestCommands $commands, Actor $agent) use ($r1, $r2): void {
            $commands->claim(new Claim($r1), $agent);

            foreach (range(1, 11) as $reply) {
                $commands->reply(new Reply($r1, "Progress note {$reply}."), $agent);
            }

            $commands->reply(new Reply($r1, 'Should the tax lines use `Money` too?', true), $agent);
            $commands->markWontDo(new MarkWontDo($r2, 'The totals already use integer cents.'), $agent);
        });

        $page = $this->updates('');

        self::assertSame([[$r1->toString(), 10, 3], [$r2->toString(), 1, 0]], array_map(
            static fn (array $updated): array => [$updated['request']['id'], count($updated['newEvents']), $updated['omittedEvents']],
            $page['requests'],
        ));
        $r1Events = $page['requests'][0]['newEvents'];
        self::assertSame(['Progress note 3.', 'Should the tax lines use `Money` too?'], [$r1Events[0]['body'], $r1Events[9]['body']]);
        self::assertSame(['in_progress'], array_values(array_unique(array_column($r1Events, 'statusAfter'))));
        self::assertSame(['request.wont_do', 'wont_do'], [$page['requests'][1]['newEvents'][0]['type'], $page['requests'][1]['newEvents'][0]['statusAfter']]);
        self::assertSame([2, false, $page['requests'][1]['request']['lastEventSeq'], ['review.started']], [$page['awaitingTotal'], $page['hasMore'], $page['cursor'], array_column($page['reviewEvents'], 'type')]);
    }

    #[TestDox('Without a cursor, an agent does not wait for a request that another agent holds')]
    public function testAgentSkipsRequestsThatAnotherAgentHolds(): void
    {
        [$r1, $r2, $r3] = $this->requests;
        self::asAgent($this->user, static fn (RequestCommands $commands, Actor $agent): array => $commands->claim(new Claim($r1), $agent), 'codex desktop');
        self::asAgent($this->user, static fn (RequestCommands $commands, Actor $agent): array => $commands->claim(new Claim($r3), $agent));
        [, $secret] = self::storeToken($this->user, name: self::AGENT_NAME);

        $page = self::assertApiResponse(self::bearerRequest($this->client, $secret, 'GET', "/api/v1/reviews/{$this->reviewId}/updates"), 200, 'updates-page.schema.json');

        self::assertSame([$r2->toString(), $r3->toString()], array_column(array_column($page['requests'], 'request'), 'id'));
        self::assertSame(2, $page['awaitingTotal']);
    }

    #[TestDox('With a cursor, groups events by request and leaves out own events unless includeOwn is set')]
    public function testCursorGroupsEventsAndLeavesOutOwnEvents(): void
    {
        [$r1, $r2] = $this->requests;
        self::asAgent($this->user, static function (RequestCommands $commands, Actor $agent) use ($r1, $r2): void {
            $commands->claim(new Claim($r1), $agent);
            $commands->claim(new Claim($r2), $agent);
            $commands->linkCommits(new LinkCommits(CommitSha::fromString(str_repeat('e', 40)), [$r1, $r2], 'One refactoring covers both.'), $agent, []);
        });
        self::requestCommand($this->client, $r2->toString(), 'comments', ['body' => 'Keep the old method as deprecated.']);
        self::asAgent($this->user, static fn (RequestCommands $commands, Actor $agent): array => $commands->reply(new Reply($r1, 'Done with the totals.'), $agent));

        $groups = static fn (array $page): array => array_map(
            static fn (array $updated): array => [$updated['request']['id'], array_column($updated['newEvents'], 'type'), $updated['omittedEvents']],
            $page['requests'],
        );
        $withoutOwn = $this->updates("?cursor={$this->cursor}");
        $withOwn = $this->updates("?cursor={$this->cursor}&includeOwn=true");

        self::assertSame([
            [$r1->toString(), ['request.claimed', 'commit.linked', 'request.commented'], 0],
            [$r2->toString(), ['request.claimed', 'commit.linked'], 0],
        ], $groups($withoutOwn));
        self::assertSame([
            [$r1->toString(), ['request.claimed', 'commit.linked', 'request.commented'], 0],
            [$r2->toString(), ['request.claimed', 'commit.linked', 'request.commented'], 0],
        ], $groups($withOwn));
        $linked = $withoutOwn['requests'][0]['newEvents'][1];
        self::assertSame([null, [$r1->toString(), $r2->toString()], null], [$linked['requestId'], $linked['requestIds'], $linked['statusAfter']]);
        self::assertSame([[], false], [$withoutOwn['reviewEvents'], $withoutOwn['hasMore']]);
    }

    #[TestDox('hasMore and cursor page through the events')]
    public function testHasMoreAndCursorPageThroughEvents(): void
    {
        [$r1] = $this->requests;
        self::asAgent($this->user, static function (RequestCommands $commands, Actor $agent) use ($r1): void {
            foreach (range(1, 5) as $reply) {
                $commands->reply(new Reply($r1, "Progress note {$reply}."), $agent);
            }
        });
        $pages = [];
        $cursor = $this->cursor;

        do {
            $page = $this->updates("?cursor={$cursor}&limit=2");
            $bodies = array_column(array_merge(...array_column($page['requests'], 'newEvents')), 'body');
            $pages[] = [$bodies, $page['hasMore'], $page['cursor'] - $this->cursor];
            $cursor = $page['cursor'];
        } while ($page['hasMore']);

        self::assertSame([
            [['Progress note 1.', 'Progress note 2.'], true, 2],
            [['Progress note 3.', 'Progress note 4.'], true, 4],
            [['Progress note 5.'], false, 5],
        ], $pages);
    }

    #[TestDox('A cursor ahead of the server gives 422')]
    public function testCursorAheadOfServerGivesInvalidInput(): void
    {
        $ahead = $this->cursor + 1;

        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/updates?cursor={$ahead}"), 422, 'invalid_input');

        self::assertSame('cursor', $error['details']['violations'][0]['field'] ?? null);
    }

    /**
     * @return array<string, mixed> The UpdatesPage
     */
    private function updates(string $query): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/updates{$query}"), 200, 'updates-page.schema.json');
    }
}
