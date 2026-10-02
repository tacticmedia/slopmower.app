<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Controller\Api\CommitDiffController;
use App\Domain\Identity\RepositoryId;
use App\Entity\User;
use App\Git\PinRefs;
use App\Git\TreeEntry;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Q9. The agent claimed a request on lines 10 to 12 of the invoice totals, which are three distinctive lines.
 */
final class CommitDiffApiTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string TAX_ROUNDING = 'src/Billing/TaxRounding.php';
    private const string LOGO = 'public/logo.png';
    private const string LARGE = 'var/large.txt';
    private const string TEST = 'tests/Billing/InvoiceTotalsTest.php';
    private const string AUDIT = 'src/Billing/Audit.php';
    private const string RENAMED = 'src/Billing/Totals.php';

    private const array ANCHORED_LINES = ['    return Money::ofCents($total);', '    // Rounding happens in TaxRounding.', "    \$audit->record('totals');"];

    private GitRepositoryBuilder $source;

    private string $reviewCommit;

    private KernelBrowser $client;

    private User $user;

    private string $repositoryId;

    private string $secret;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->reviewCommit = $this->source->commit([
            self::INVOICE_TOTALS => self::invoiceTotals(true),
            self::TAX_ROUNDING => "<?php\n\nfinal class TaxRounding\n{\n    public const int SCALE = 2;\n}\n",
            self::LOGO => "\x89PNG\r\n\x1a\n\0\0\0\rIHDR",
        ], 'Add invoice totals');
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $reviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        $this->requestId = (string) self::openRequest($this->client, $reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 10, 'endLine' => 12]])['id'];
        [, $this->secret] = self::storeToken($this->user);
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/claim");
    }

    #[TestDox('Returns the files of the commit with blob SHAs, counts, and touchesAnchor')]
    public function testReturnsFilesWithBlobShasCountsAndTouchesAnchor(): void
    {
        $fix = $this->fixCommit();

        $diff = $this->commitDiff($fix);

        self::assertSame(
            [$this->requestId, $fix, $this->reviewCommit, 'Use Money for totals', "Use Money for totals\n\nThe tests cover cents.\n", 'Test', 'test@example.invalid', false],
            [$diff['requestId'], $diff['commitSha'], $diff['parentSha'], $diff['subject'], $diff['message'], $diff['authorName'], $diff['authorEmail'], $diff['truncated']],
        );
        self::assertSame([
            $this->file('modified', self::INVOICE_TOTALS, $fix, [2, 0], touchesAnchor: true),
            $this->file('modified', self::LOGO, $fix, null),
            $this->file('deleted', self::TAX_ROUNDING, $fix, [0, 6]),
            $this->file('added', self::TEST, $fix, [3, 0]),
            $this->file('added', self::LARGE, $fix, [1, 0], tooLarge: true),
        ], $diff['files']);
    }

    #[TestDox('The location has the target linked_commit')]
    public function testLocationHasTargetLinkedCommit(): void
    {
        $fix = $this->fixCommit();

        self::assertSame(
            ['target' => 'linked_commit', 'targetSha' => $fix, 'status' => 'shifted', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 12, 'endLine' => 14], 'confidence' => 1, 'reason' => null],
            $this->commitDiff($fix)['location'],
        );
    }

    #[TestDox('touchesAnchor follows location.path, and the anchor path when the location is orphaned')]
    public function testTouchesAnchorFollowsLocationPathOrAnchorPath(): void
    {
        $moved = $this->source->commit([self::INVOICE_TOTALS => self::invoiceTotals(false), self::AUDIT => "<?php\n\n".implode("\n", self::ANCHORED_LINES)."\n"], 'Move the audit');
        $this->source->switch('rename')->git('reset', '--quiet', '--hard', $this->reviewCommit);
        $this->source->git('mv', self::INVOICE_TOTALS, self::RENAMED);
        $renamed = $this->source->commit([self::RENAMED => self::invoiceTotals(false), 'CHANGELOG.md' => "Renamed\n"], 'Rename the totals');
        $this->fetchAndLink($moved, $renamed);

        $movedDiff = $this->commitDiff($moved);
        $renamedDiff = $this->commitDiff($renamed);

        self::assertSame(['shifted', self::AUDIT], [$movedDiff['location']['status'], $movedDiff['location']['path']]);
        self::assertSame([[self::AUDIT, null, true], [self::INVOICE_TOTALS, null, false]], self::touches($movedDiff));
        self::assertSame(['orphaned', 'region_deleted'], [$renamedDiff['location']['status'], $renamedDiff['location']['reason']]);
        self::assertSame([[self::RENAMED, self::INVOICE_TOTALS, true], ['CHANGELOG.md', null, false]], self::touches($renamedDiff));
    }

    #[TestDox('More than 300 files set truncated and keep the file that touches the anchor')]
    public function testMoreThan300FilesSetTruncatedAndKeepAnchoredFile(): void
    {
        $files = [self::INVOICE_TOTALS => "<?php\n\n".self::invoiceTotals(true)];

        foreach (range(1, CommitDiffController::MAX_FILES + 1) as $number) {
            $files[\sprintf('a/%03d.txt', $number)] = "{$number}\n";
        }

        $wide = $this->source->commit($files, 'Touch many files');
        $this->fetchAndLink($wide);

        $diff = $this->commitDiff($wide);

        self::assertSame([CommitDiffController::MAX_FILES, true, self::INVOICE_TOTALS, true], [\count($diff['files']), $diff['truncated'], $diff['files'][0]['path'], $diff['files'][0]['touchesAnchor']]);
    }

    #[TestDox('A path that RepoPath rejects is left out and sets truncated')]
    public function testRejectedPathIsLeftOutAndSetsTruncated(): void
    {
        $dash = $this->source->commit(['-dash.txt' => "Dash\n", 'CHANGELOG.md' => "Dash\n"], 'Add a file with a leading dash');
        $this->fetchAndLink($dash);

        $diff = $this->commitDiff($dash);

        self::assertSame([['CHANGELOG.md'], true], [array_column((array) $diff['files'], 'path'), $diff['truncated']]);
    }

    #[TestDox('A commit that is not linked gives 404')]
    public function testCommitNotLinkedGivesNotFound(): void
    {
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}/commits/{$this->reviewCommit}"), 404, 'not_found');
    }

    #[TestDox('An upper-case SHA gives invalid_input on commitSha')]
    public function testUpperCaseShaGivesInvalidInput(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}/commits/".strtoupper($this->reviewCommit)), 422, 'invalid_input');

        self::assertSame(['commitSha'], array_column((array) ($error['details']['violations'] ?? []), 'field'));
    }

    #[TestDox('An available link whose commit left the clone gives 409 commits_not_available')]
    public function testAvailableLinkWithoutCommitGivesCommitsNotAvailable(): void
    {
        $this->source->switch('gone')->git('reset', '--quiet', '--hard', $this->reviewCommit);
        $gone = $this->source->commit(['CHANGELOG.md' => "Gone\n"], 'Leave the clone');
        $this->fetchAndLink($gone);
        $repository = self::freshRepository(RepositoryId::fromString($this->repositoryId));
        self::cloneGit($repository, 'update-ref', '-d', 'refs/heads/gone');
        self::cloneGit($repository, 'update-ref', '-d', PinRefs::COMMITS.$gone);
        self::cloneGit($repository, 'gc', '--quiet', '--prune=now');

        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}/commits/{$gone}"), 409, 'commits_not_available');

        self::assertSame(['pendingCommits' => [$gone]], $error['details']);
    }

    #[TestDox('A pending link gives 409 commits_not_available')]
    public function testPendingLinkGivesCommitsNotAvailable(): void
    {
        $pending = $this->source->switch('pending')->commit(['CHANGELOG.md' => "Pending\n"], 'Not fetched yet');
        self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $pending, 'requestIds' => [$this->requestId]], schema: 'link-commits-result.schema.json');

        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}/commits/{$pending}"), 409, 'commits_not_available');

        self::assertSame(['pendingCommits' => [$pending]], $error['details']);
    }

    #[TestDox('Another tenant gets 404 for a linked commit')]
    public function testAnotherTenantGetsNotFound(): void
    {
        $fix = $this->fixCommit();
        self::signIn($this->client, 'other');

        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}/commits/{$fix}"), 404, 'not_found');
    }

    /**
     * Two lines on top shift the anchored lines to 12 to 14. The large file has one line.
     */
    private function fixCommit(): string
    {
        $this->source->git('rm', '--quiet', self::TAX_ROUNDING);
        $fix = $this->source->commit([
            self::INVOICE_TOTALS => "<?php\n\n".self::invoiceTotals(true),
            self::LOGO => "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x10",
            self::TEST => "<?php\n\nfinal class InvoiceTotalsTest {}\n",
            self::LARGE => str_repeat('a', TreeEntry::MAX_RENDERABLE_BYTES + 1),
        ], "Use Money for totals\n\nThe tests cover cents.");
        $this->fetchAndLink($fix);

        return $fix;
    }

    private function fetchAndLink(string ...$commits): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->user->getTenantId());
        self::fetchRepository()->fetchNow(RepositoryId::fromString($this->repositoryId));

        foreach ($commits as $commit) {
            $result = self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $commit, 'requestIds' => [$this->requestId]], schema: 'link-commits-result.schema.json');
            self::assertSame('available', $result['state']);
        }
    }

    /**
     * @return array<string, mixed> The CommitDiff
     */
    private function commitDiff(string $commit): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}/commits/{$commit}"), 200, 'commit-diff.schema.json');
    }

    /**
     * @param array{int, int}|null $counts Additions and deletions, null for a binary file
     *
     * @return array<string, mixed> The CommitDiffFile
     */
    private function file(string $status, string $path, string $commit, ?array $counts, bool $tooLarge = false, bool $touchesAnchor = false): array
    {
        $old = 'added' === $status ? null : $this->source->revParse("{$commit}^:{$path}");
        $new = 'deleted' === $status ? null : $this->source->revParse("{$commit}:{$path}");

        return [
            'status' => $status,
            'path' => $path,
            'oldPath' => null,
            'oldBlobSha' => $old,
            'newBlobSha' => $new,
            'oldMode' => null === $old ? null : '100644',
            'newMode' => null === $new ? null : '100644',
            'additions' => $counts[0] ?? null,
            'deletions' => $counts[1] ?? null,
            'binary' => null === $counts,
            'tooLarge' => $tooLarge,
            'touchesAnchor' => $touchesAnchor,
        ];
    }

    /**
     * @param array<string, mixed> $diff
     *
     * @return list<array{mixed, mixed, mixed}> Path, old path, and touchesAnchor of each file
     */
    private static function touches(array $diff): array
    {
        return array_values(array_map(static fn (array $file): array => [$file['path'], $file['oldPath'], $file['touchesAnchor']], (array) $diff['files']));
    }

    /**
     * Thirty lines `    $total += N;`, with {@see ANCHORED_LINES} as lines 10 to 12 when $withAnchoredLines.
     */
    private static function invoiceTotals(bool $withAnchoredLines): string
    {
        $lines = array_map(static fn (int $line): string => "    \$total += {$line};", range(1, 30));
        array_splice($lines, 9, 3, $withAnchoredLines ? self::ANCHORED_LINES : []);

        return implode("\n", $lines)."\n";
    }
}
