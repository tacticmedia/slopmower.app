<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * V15 with real git: the source review is at the first commit of "billing", the target review at a later commit.
 */
final class CarryOverTest extends WebTestCase
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

    private const string LEDGER = 'src/Billing/ledger.txt';

    private const string TOTALS = 'src/Billing/InvoiceTotals.php';

    private const string TOTAL_FQN = 'App\Billing\InvoiceTotals::total()';

    private KernelBrowser $client;

    private User $user;

    private GitRepositoryBuilder $source;

    private string $repositoryId;

    private string $sourceReviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->source->commit([self::LEDGER => self::ledger(1, 40), self::TOTALS => self::invoiceTotals()]);
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $this->sourceReviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
    }

    #[TestDox('Exact and shifted sources keep their kind and get the new range and snapshot')]
    public function testExactAndShiftedKeepTheKind(): void
    {
        $exact = $this->openSource(['kind' => 'range', 'path' => self::LEDGER, 'range' => ['startLine' => 5, 'endLine' => 7]]);
        $shifted = $this->openSource(['kind' => 'line', 'path' => self::LEDGER, 'range' => ['startLine' => 30, 'endLine' => 30]]);
        $symbol = $this->openSource(['kind' => 'symbol', 'path' => self::TOTALS, 'symbol' => ['fqn' => self::TOTAL_FQN]]);
        $targetId = $this->startTarget([
            self::LEDGER => self::ledger(1, 20)."    // Added.\n    // Added.\n".self::ledger(21, 40),
            self::TOTALS => str_replace("use App\\Attribute\\Audited;\n", "use App\\Attribute\\Audited;\nuse App\\Attribute\\Logged;\n", self::invoiceTotals()),
        ]);

        $items = $this->carryOver($targetId);

        self::assertSame(['carried', 'exact'], [$items[$exact]['outcome'], $items[$exact]['location']]);
        self::assertSame(['carried', 'shifted'], [$items[$shifted]['outcome'], $items[$shifted]['location']]);
        self::assertSame(['carried', 'shifted'], [$items[$symbol]['outcome'], $items[$symbol]['location']]);
        $exactCopy = $this->carried($items[$exact], $targetId);
        $shiftedCopy = $this->carried($items[$shifted], $targetId);
        $symbolCopy = $this->carried($items[$symbol], $targetId);
        self::assertSame(['range', ['startLine' => 5, 'endLine' => 7], ['    $total += 5;', '    $total += 6;', '    $total += 7;']], self::anchorFacts($exactCopy));
        self::assertSame(['line', ['startLine' => 32, 'endLine' => 32], ['    $total += 30;']], self::anchorFacts($shiftedCopy));
        self::assertSame(['symbol', ['startLine' => 25, 'endLine' => 32]], \array_slice(self::anchorFacts($symbolCopy), 0, 2));
        self::assertSame(self::TOTAL_FQN, $symbolCopy['anchor']['symbol']['fqn']);
        self::assertSame([0, 0, 0], [$exactCopy['commentCount'], $shiftedCopy['commentCount'], $symbolCopy['commentCount']]);
    }

    #[TestDox('A changed source keeps its kind at the located range and gets the code-changed comment')]
    public function testChangedAddsTheCodeChangedComment(): void
    {
        $changed = $this->openSource(['kind' => 'range', 'path' => self::LEDGER, 'range' => ['startLine' => 10, 'endLine' => 13]]);
        $targetId = $this->startTarget([self::LEDGER => self::ledger(1, 10)."    \$total += 1100;\n".self::ledger(12, 40)]);

        $items = $this->carryOver($targetId);

        self::assertSame(['carried', 'changed'], [$items[$changed]['outcome'], $items[$changed]['location']]);
        $copy = $this->carried($items[$changed], $targetId);
        self::assertSame(['range', ['startLine' => 10, 'endLine' => 13], ['    $total += 10;', '    $total += 1100;', '    $total += 12;', '    $total += 13;']], self::anchorFacts($copy));
        self::assertSame(['request.opened', 'request.commented'], array_column($copy['thread'], 'type'));
        self::assertStringContainsString('changed between the source review commit', (string) $copy['thread'][1]['body']);
    }

    #[TestDox('An orphaned source whose file exists becomes a file request with the old snapshot in its first comment')]
    public function testOrphanedWithPathBecomesFileAnchor(): void
    {
        $orphaned = $this->openSource(['kind' => 'range', 'path' => self::LEDGER, 'range' => ['startLine' => 10, 'endLine' => 12]]);
        $targetId = $this->startTarget([self::LEDGER => self::ledger(1, 9).self::ledger(13, 40)]);

        $items = $this->carryOver($targetId);

        self::assertSame(['carried', 'orphaned', null], [$items[$orphaned]['outcome'], $items[$orphaned]['location'], $items[$orphaned]['reason']]);
        $copy = $this->carried($items[$orphaned], $targetId);
        self::assertSame(['file', self::LEDGER, null], [$copy['anchor']['kind'], $copy['anchor']['path'], $copy['anchor']['range']]);
        self::assertStringContainsString("lines 10 to 12 of `src/Billing/ledger.txt`:\n\n```\n    \$total += 10;\n    \$total += 11;\n    \$total += 12;\n```", (string) $copy['thread'][1]['body']);
    }

    #[TestDox('An orphaned source whose file is gone is not carried and gives the orphan reason')]
    public function testOrphanedWithoutPathIsNotCarried(): void
    {
        $orphaned = $this->openSource(['kind' => 'range', 'path' => self::LEDGER, 'range' => ['startLine' => 10, 'endLine' => 12]]);
        $this->source->git('rm', '--quiet', self::LEDGER);
        $targetId = $this->startTarget([]);

        $items = $this->carryOver($targetId);

        self::assertSame(['not_carried', null, 'orphaned', 'file_deleted'], [$items[$orphaned]['outcome'], $items[$orphaned]['requestId'], $items[$orphaned]['location'], $items[$orphaned]['reason']]);
        self::assertSame(0, $this->requestsOf($targetId)['total']);
    }

    #[TestDox('A general source carries as a general request with a link to its source')]
    public function testGeneralRequestCarriesAsGeneral(): void
    {
        $general = $this->openSource(null, "Add a full-screen focus mode.\n\nIt hides the file tree.");
        $targetId = $this->startTarget([]);

        $items = $this->carryOver($targetId);

        self::assertSame(['carried', null], [$items[$general]['outcome'], $items[$general]['location']]);
        $copy = $this->carried($items[$general], $targetId);
        self::assertSame([null, "Add a full-screen focus mode.\n\nIt hides the file tree.", 'open'], [$copy['anchor'], $copy['body'], $copy['status']]);
    }

    #[TestDox('Sources in in_progress and ready_for_review are listed as not carried, with their status')]
    public function testClaimedAndReadySourcesAreNotCarried(): void
    {
        $inProgress = $this->openSource(null, 'Rename the totals.');
        $ready = $this->openSource(null, 'Drop the float cast.');
        self::asAgent($this->user, static function (RequestCommands $commands, Actor $agent) use ($inProgress, $ready): void {
            $commands->claim(new Claim(RequestId::fromString($inProgress)), $agent);
            $commands->claim(new Claim(RequestId::fromString($ready)), $agent);
            $commands->markReadyForReview(new MarkReadyForReview(RequestId::fromString($ready), 'Dropped the cast.'), $agent);
        });
        $targetId = $this->startTarget([]);

        $items = $this->carryOver($targetId);

        self::assertSame(['not_carried', 'in_progress'], [$items[$inProgress]['outcome'], $items[$inProgress]['reason']]);
        self::assertSame(['not_carried', 'ready_for_review'], [$items[$ready]['outcome'], $items[$ready]['reason']]);
        self::assertSame(0, $this->requestsOf($targetId)['total']);
    }

    #[TestDox('A second call reports already_carried and writes nothing')]
    public function testSecondCallReportsAlreadyCarried(): void
    {
        $general = $this->openSource(null, 'Add a full-screen focus mode.');
        $targetId = $this->startTarget([]);
        $first = $this->carryOver($targetId);
        $lastEventSeq = self::review($this->client, $targetId)['lastEventSeq'];

        $second = $this->carryOver($targetId);

        self::assertSame(['already_carried', $first[$general]['requestId']], [$second[$general]['outcome'], $second[$general]['requestId']]);
        self::assertSame([1, $lastEventSeq], [$this->requestsOf($targetId)['total'], self::review($this->client, $targetId)['lastEventSeq']]);
    }

    #[TestDox('The source review and its requests do not change')]
    public function testSourceReviewIsUnchanged(): void
    {
        $this->openSource(['kind' => 'range', 'path' => self::LEDGER, 'range' => ['startLine' => 10, 'endLine' => 12]]);
        $this->openSource(null, 'Add a full-screen focus mode.');
        $before = [self::review($this->client, $this->sourceReviewId), $this->requestsOf($this->sourceReviewId)];
        $targetId = $this->startTarget([self::LEDGER => self::ledger(1, 9).self::ledger(13, 40)]);

        $this->carryOver($targetId);

        self::assertSame($before, [self::review($this->client, $this->sourceReviewId), $this->requestsOf($this->sourceReviewId)]);
    }

    #[TestDox('The review itself, a review of another repository, and a closed target give 422 invalid_input')]
    public function testRefusesInvalidSourcesAndClosedTarget(): void
    {
        $this->newSource('ledger')->commit(['README.md' => "# Ledger\n"]);
        $otherReviewId = (string) self::startReviewThroughApi($this->client, self::connectAndClone($this->client, 'ledger'))['id'];
        $targetId = $this->startTarget([]);

        self::assertInvalidField($this->carryOverResponse($targetId, $targetId), 'fromReviewId');
        self::assertInvalidField($this->carryOverResponse($targetId, $otherReviewId), 'fromReviewId');
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$targetId}/close"), 200, 'review.schema.json');
        self::assertInvalidField($this->carryOverResponse($targetId, $this->sourceReviewId), '');
    }

    #[TestDox('A source review of another tenant gives 404 not_found')]
    public function testInvisibleSourceGivesNotFound(): void
    {
        $otherReview = self::storeReview(TenantFixtures::of(self::getContainer())->withLogin('other')->create(), 'billing');
        $targetId = $this->startTarget([]);

        self::assertApiError($this->carryOverResponse($targetId, $otherReview->getId()->toString()), 404, 'not_found');
    }

    /**
     * @param array<string, mixed>|null $anchor
     *
     * @return string The request ID
     */
    private function openSource(?array $anchor, string $body = 'Use `Money` instead of `float` for totals.'): string
    {
        return (string) self::openRequest($this->client, $this->sourceReviewId, $anchor, $body)['id'];
    }

    /**
     * @param array<string, string> $files Content by path, committed on main before the start
     *
     * @return string The review ID
     */
    private function startTarget(array $files): string
    {
        $this->source->commit($files);

        return (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
    }

    /**
     * @return array<string, array<string, mixed>> The items by source request ID
     */
    private function carryOver(string $targetId): array
    {
        $result = self::assertApiResponse($this->carryOverResponse($targetId, $this->sourceReviewId), 200, 'carry-over-result.schema.json');
        self::assertSame([$targetId, $this->sourceReviewId], [$result['reviewId'], $result['fromReviewId']]);
        $items = [];

        foreach ($result['items'] as $item) {
            $items[$item['source']['id']] = $item;
        }

        return $items;
    }

    private function carryOverResponse(string $targetId, string $fromReviewId): Response
    {
        return self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$targetId}/carry-over", ['fromReviewId' => $fromReviewId]);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed> The carried Request, checked against its item and its source
     */
    private function carried(array $item, string $targetId): array
    {
        $copy = self::readRequest($this->client, (string) $item['requestId']);
        $anchorCommitSha = null === $copy['anchor'] ? null : $copy['anchor']['commitSha'];
        self::assertSame([$targetId, ['reviewId' => $this->sourceReviewId, 'requestId' => $item['source']['id']]], [$copy['reviewId'], $copy['carriedFrom']]);
        self::assertContains($anchorCommitSha, [null, $copy['reviewCommitSha']]);

        return $copy;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{mixed, mixed, mixed} The kind, the range, and the snapshot lines of the anchor
     */
    private static function anchorFacts(array $request): array
    {
        return [$request['anchor']['kind'], $request['anchor']['range'], $request['anchor']['snapshot']['lines'] ?? null];
    }

    /**
     * @return array<string, mixed> The RequestPage
     */
    private function requestsOf(string $reviewId): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$reviewId}/requests"), 200, 'lists.schema.json#/$defs/RequestPage');
    }

    /**
     * @return array<string, mixed> The Review
     */
    private static function review(KernelBrowser $client, string $reviewId): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}"), 200, 'review.schema.json');
    }

    private static function assertInvalidField(Response $response, string $field): void
    {
        self::assertSame([$field], array_column(self::assertApiError($response, 422, 'invalid_input')['details']['violations'] ?? [], 'field'));
    }

    /**
     * The lines `    $total += N;` for N from $first to $last.
     */
    private static function ledger(int $first, int $last): string
    {
        return implode('', array_map(static fn (int $n): string => "    \$total += {$n};\n", range($first, $last)));
    }

    private static function invoiceTotals(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/php/InvoiceTotals.php');
    }
}
