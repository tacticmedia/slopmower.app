<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Query\RequestFilter;
use App\Application\Query\RequestSort;
use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\Reply;
use App\Http\Cursor;
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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * r1 open line 10, r2 open range 9-10, r3 open file, all of InvoiceTotals.php. r4 in_progress file README.md, awaiting
 * the user. r5 wont_do range of TaxRounding.php. r6 open general request. Updated last: r1, r4, r5, r6, r3, r2.
 */
final class RequestListFilterTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private string $reviewId;

    /** @var array<string, string> Request ID by name */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit([...self::codeFiles(), self::longPath() => "Notes\n"]);
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        self::mockTime('2026-09-29T07:00:00Z');
        $anchors = [
            'r1' => ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 10, 'endLine' => 10]],
            'r2' => ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 9, 'endLine' => 10]],
            'r3' => ['kind' => 'file', 'path' => self::INVOICE_TOTALS],
            'r4' => ['kind' => 'file', 'path' => 'README.md'],
            'r5' => ['kind' => 'range', 'path' => 'src/Billing/TaxRounding.php', 'range' => ['startLine' => 1, 'endLine' => 2]],
            'r6' => null,
        ];

        foreach ($anchors as $name => $anchor) {
            self::mockTime('+1 second');
            $this->ids[$name] = (string) self::openRequest($this->client, $this->reviewId, $anchor)['id'];
        }

        self::mockTime('+1 second');
        self::requestCommand($this->client, $this->ids['r5'], 'wont-do', ['reason' => self::WONT_DO_REASON]);
        self::mockTime('+1 second');
        $r4 = RequestId::fromString($this->ids['r4']);
        self::asAgent($user, static function (RequestCommands $commands, Actor $agent) use ($r4): void {
            $commands->claim(new Claim($r4), $agent);
            $commands->reply(new Reply($r4, 'Is README.md still current?', true), $agent);
        });
        self::mockTime('+1 second');
        self::requestCommand($this->client, $this->ids['r1'], 'comments', ['body' => 'Keep the old method as deprecated.']);
    }

    #[TestDox('Filters by status, awaiting, and path prefix')]
    public function testFiltersByStatusAwaitingAndPathPrefix(): void
    {
        $listed = array_map($this->listedNames(...), [
            'status=open',
            'status=wont_do,in_progress',
            'awaiting=user',
            'awaiting=none,agent',
            'path=src/Billing/',
            'path=src/Billing/Tax',
            'path=src_',
            'path=%25Totals.php',
            'outdated=false',
        ]);

        self::assertSame([
            ['r1', 'r2', 'r3', 'r6'],
            ['r4', 'r5'],
            ['r4'],
            ['r1', 'r2', 'r3', 'r5', 'r6'],
            ['r1', 'r2', 'r3', 'r5'],
            ['r5'],
            [],
            [],
            [],
        ], $listed);
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/requests?status=open,done"), 422, 'invalid_input');
    }

    #[TestDox('Sorts by created, updated, and path')]
    public function testSortsByCreatedUpdatedAndPath(): void
    {
        self::assertSame(['r1', 'r2', 'r3', 'r4', 'r5', 'r6'], $this->listedNames('sort=created'));
        self::assertSame(['r1', 'r4', 'r5', 'r6', 'r3', 'r2'], $this->listedNames('sort=updated'));
        self::assertSame(['r6', 'r4', 'r3', 'r2', 'r1', 'r5'], $this->listedNames('sort=path'));
    }

    #[TestDox('total counts all matches regardless of the cursor')]
    public function testTotalCountsAllMatchesRegardlessOfCursor(): void
    {
        $pages = [];
        $query = 'sort=path&limit=1';

        do {
            $page = $this->page($query);
            $pages[] = [$page['total'], $this->names(array_column($page['items'], 'id'))];
            $query = 'sort=path&limit=1&cursor='.$page['nextCursor'];
        } while (null !== $page['nextCursor']);

        self::assertSame([[6, ['r6']], [6, ['r4']], [6, ['r3']], [6, ['r2']], [6, ['r1']], [6, ['r5']]], $pages);
        self::assertSame(4, $this->page('status=open&limit=1')['total']);

        $cursor = $this->page('sort=path&limit=2')['nextCursor'];
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/requests?sort=updated&limit=2&cursor={$cursor}"), 422, 'invalid_input');
    }

    #[TestDox('A path cursor stays within the cursor limit for a long path')]
    public function testPathCursorStaysShortForLongPath(): void
    {
        $first = (string) self::openRequest($this->client, $this->reviewId, ['kind' => 'file', 'path' => self::longPath()])['id'];
        $second = (string) self::openRequest($this->client, $this->reviewId, ['kind' => 'file', 'path' => self::longPath()])['id'];

        $pageOne = $this->page('path=docs/&sort=path&limit=1');
        $pageTwo = $this->page('path=docs/&sort=path&limit=1&cursor='.$pageOne['nextCursor']);

        self::assertGreaterThan(1500, strlen(self::longPath()));
        self::assertSame([[$first], [$second], null], [array_column($pageOne['items'], 'id'), array_column($pageTwo['items'], 'id'), $pageTwo['nextCursor']]);
    }

    #[TestDox('A cursor key out of range gives 422')]
    public function testCursorKeyOutOfRangeGivesInvalidInput(): void
    {
        $filters = new RequestFilter(sort: RequestSort::Updated)->cursorFilters();

        foreach ([PHP_INT_MAX, -62135596801] as $unixSeconds) {
            $cursor = Cursor::encode([$unixSeconds, $this->ids['r1']], $filters);

            self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/requests?sort=updated&cursor={$cursor}"), 422, 'invalid_input');
        }
    }

    /**
     * Eight directories of 200 characters.
     */
    private static function longPath(): string
    {
        return 'docs/'.implode('/', array_map(static fn (string $letter): string => str_repeat($letter, 200), range('a', 'h'))).'/notes.txt';
    }

    /**
     * @return array<string, mixed> The RequestPage
     */
    private function page(string $query): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/requests?{$query}"), 200, 'lists.schema.json#/$defs/RequestPage');
    }

    /**
     * @return list<string>
     */
    private function listedNames(string $query): array
    {
        $page = $this->page($query);
        self::assertSame(count($page['items']), $page['total']);

        return $this->names(array_column($page['items'], 'id'));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function names(array $ids): array
    {
        return array_map(fn (string $id): string => array_search($id, $this->ids, true) ?: self::fail("Unknown request {$id}."), $ids);
    }
}
