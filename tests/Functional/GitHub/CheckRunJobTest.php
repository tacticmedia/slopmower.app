<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\QueueCheckRun;
use App\Application\RequestCommands;
use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\RequestDecider;
use App\Entity\PullRequest;
use App\Entity\Repository;
use App\Entity\User;
use App\EventStore\CommandExecutor;
use App\Git\GitReader;
use App\GitHub\Payload\PullRequestPayload;
use App\Message\CheckRunJob;
use App\Message\GitHubPullRequestJob;
use App\MessageHandler\PullRequestStateHandler;
use App\Repository\RepositoryRepository;
use App\Repository\ReviewRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;

/**
 * docs/specs/webhooks-and-live-updates.md, "Check run". Pull request 42 of acme/billing, a local bare repository with
 * src/Invoice.php at the head of main, and recorded GitHub answers.
 */
final class CheckRunJobTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use GitHubRows;
    use LocalGitHub;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string URL = 'https://github.com/acme/billing/pull/42';

    private const string PATH = 'src/Invoice.php';

    private const string BODY = 'Use `Money` instead of `float` for totals.';

    private const array PERMISSIONS = ['contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'read', 'checks' => 'write'];

    /** The head SHA of pull-request-acme-billing-42.json, which the clone does not have. */
    private const string RECORDED_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private User $user;

    private Repository $repository;

    private CommitSha $head;

    private ReviewId $reviewId;

    #[TestDox('A link creates a check run on the head SHA with the summary and the annotations')]
    public function testLinkCreatesTheCheckRun(): void
    {
        $this->reviewOfClonedRepository();
        $pullRequest = $this->storePullRequest();
        $anchored = $this->openRequest(3);
        $general = $this->openRequest(null, 'Add a test for the rounding of totals.');

        $this->claimAndLink($anchored, $general);
        self::runCheckRunJobs();

        $requestUrl = static fn (RequestId $id): string => "http://localhost/requests/{$id}";
        $reviewUrl = "http://localhost/reviews/{$this->reviewId}/requests";
        self::assertSame([['POST', 'repos/acme/billing/check-runs', [
            'name' => 'Slopmower (jan)',
            'head_sha' => $this->head->toString(),
            'external_id' => $pullRequest->getId()->toString(),
            'details_url' => $reviewUrl,
            'status' => 'in_progress',
            'output' => [
                'title' => '0 of 2 requests finished',
                'summary' => "### [Billing totals]({$reviewUrl})\n\n0 of 2 finished.\n\n"
                    ."- In progress: [Use `Money` instead of `float` for totals.]({$requestUrl($anchored)})\n"
                    ."- In progress: [Add a test for the rounding of totals.]({$requestUrl($general)}), general request without an annotation",
                'annotations' => [[
                    'path' => self::PATH,
                    'start_line' => 3,
                    'end_line' => 3,
                    'annotation_level' => 'warning',
                    'title' => self::BODY,
                    'message' => 'In progress in Slopmower: '.$requestUrl($anchored),
                ]],
            ],
        ]]], self::checkRunCalls());
    }

    #[TestDox('120 anchored requests give one call with 50 annotations and two update calls')]
    public function testAnnotationsGoInGroupsOf50(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest();
        $this->claimAndLink(...$this->openRequestsAtOnce(range(1, 120)));

        self::runCheckRunJobs();

        $calls = array_map(static fn (array $call): array => [$call[0], $call[1], \count($call[2]['output']['annotations'] ?? [])], self::checkRunCalls());
        self::assertSame([
            ['POST', 'repos/acme/billing/check-runs', 50],
            ['PATCH', 'repos/acme/billing/check-runs/4', 50],
            ['PATCH', 'repos/acme/billing/check-runs/4', 20],
        ], $calls);
    }

    #[TestDox('A verify that removes an annotation creates a new run, and a reply updates nothing')]
    public function testChangedAnnotationsCreateANewRun(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest();
        $ready = $this->openRequest(3);
        $open = $this->openRequest(7);
        $this->claimAndLink($ready, $open);
        self::commands()->markReadyForReview(new MarkReadyForReview($ready, 'Totals use Money now.'), $this->agent());
        self::runCheckRunJobs();

        self::commands()->verify(new Verify($ready), $this->userActor());
        self::runCheckRunJobs();
        self::commands()->reply(new Reply($open, 'Keep the rounding mode.'), $this->userActor());
        self::runCheckRunJobs();

        $calls = array_map(static fn (array $call): array => [$call[0], $call[1], array_column($call[2]['output']['annotations'] ?? [], 'annotation_level')], self::checkRunCalls());
        self::assertSame([
            ['POST', 'repos/acme/billing/check-runs', ['notice', 'warning']],
            ['POST', 'repos/acme/billing/check-runs', ['warning']],
        ], $calls);
    }

    #[TestDox('A job with the same status and annotations updates the run')]
    public function testSameAnnotationsUpdateTheRun(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest();
        $this->claimAndLink($this->openRequest(3));
        self::runCheckRunJobs();

        $this->claimAndLink($this->openRequest(null, 'Add a test for the rounding of totals.'));
        self::runCheckRunJobs();

        $update = self::checkRunCalls()[1] ?? self::fail('No second call.');
        self::assertSame(['PATCH', 'repos/acme/billing/check-runs/4'], [$update[0], $update[1]]);
        self::assertSame(['title' => '0 of 2 requests finished'], array_intersect_key($update[2]['output'] ?? [], ['title' => true, 'annotations' => true]));
    }

    #[TestDox('A run older than 14 days gets a new run instead of an update')]
    public function testStaleRunGetsANewRun(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest();
        $this->claimAndLink($this->openRequest(3));
        self::runCheckRunJobs();

        Clock::get()->sleep(15 * 24 * 3600);
        $this->claimAndLink($this->openRequest(null, 'Add a test for the rounding of totals.'));
        self::runCheckRunJobs();

        self::assertSame(['POST', 'POST'], array_column(self::checkRunCalls(), 0));
    }

    #[TestDox('After a rename of the GitHub login, an unchanged output creates a run with the new name')]
    public function testRenamedLoginGetsANewRun(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest();
        $this->claimAndLink($this->openRequest(3));
        self::runCheckRunJobs();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->find(User::class, $this->user->getId()) ?? self::fail('The user is gone.');
        $user->recordGitHubSignIn('jan-renamed', null, null, Clock::get()->now());
        $entityManager->flush();
        $this->claimAndLink($this->openRequest(null, 'Add a test for the rounding of totals.'));
        self::runCheckRunJobs();

        self::assertSame([['POST', 'Slopmower (jan)'], ['POST', 'Slopmower (jan-renamed)']], array_map(static fn (array $call): array => [$call[0], $call[2]['name'] ?? null], self::checkRunCalls()));
    }

    #[TestDox('A reopen with the same head queues the check run of the reopened pull request')]
    public function testReopenWithTheSameHeadQueuesTheJob(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest('closed');
        $this->claimAndLink($this->openRequest(3));
        self::runCheckRunJobs();

        self::getContainer()->get(PullRequestStateHandler::class)->handleDelivery(new GitHubPullRequestJob(812345, new PullRequestPayload(
            42,
            'Use Money for invoice totals',
            'open',
            new \DateTimeImmutable('2026-10-02T00:00:00Z'),
            $this->head->toString(),
            headRepositoryFullName: 'acme/billing',
        )));
        self::runCheckRunJobs();

        self::assertSame([['POST', 'in_progress']], array_map(static fn (array $call): array => [$call[0], $call[2]['status'] ?? null], self::checkRunCalls()));
    }

    #[TestDox('A review deletion updates the check run of the pull requests that its requests linked')]
    public function testReviewDeletionQueuesTheJob(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest();
        $this->claimAndLink($this->openRequest(3));
        self::runCheckRunJobs();

        self::getContainer()->get(ReviewCommands::class)->delete($this->reviewId);
        self::runCheckRunJobs();

        self::assertSame([['POST', 'in_progress'], ['POST', 'completed']], array_map(static fn (array $call): array => [$call[0], $call[2]['status'] ?? null], self::checkRunCalls()));
    }

    #[TestDox('An installation without Checks: write requests no token')]
    public function testInstallationWithoutChecksWriteRequestsNoToken(): void
    {
        $this->reviewOfClonedRepository(['checks' => 'read'] + self::PERMISSIONS);
        $this->storePullRequest();
        $this->claimAndLink($this->openRequest(3));
        self::runQueuedMessages();
        $gitHubRequestsBefore = self::gitHubRequestCount();

        self::runCheckRunJobs();

        self::assertSame($gitHubRequestsBefore, self::gitHubRequestCount());
    }

    #[TestDox('A head that is missing after one fetch gives a run without annotations')]
    public function testMissingHeadGivesNoAnnotations(): void
    {
        $this->reviewOfClonedRepository();
        $this->claimAndLink($this->openRequest(3));
        // The read job records the head of pull-request-acme-billing-42.json and queues the check run.
        self::runQueuedMessages();

        self::runCheckRunJobs();

        [[$method, , $body]] = self::checkRunCalls();
        self::assertSame(['POST', self::RECORDED_HEAD, []], [$method, $body['head_sha'] ?? null, $body['output']['annotations'] ?? null]);
        self::assertEquals(Clock::get()->now(), self::freshRepository($this->repository->getId())->getLastFetchedAt(), 'The job fetched.');
        self::assertStringStartsWith('No annotations: the head commit '.self::RECORDED_HEAD.' is not in the clone of Slopmower. The fetch did not bring it.', $body['output']['summary'] ?? '');
    }

    #[TestDox('Two quick request events give one job')]
    public function testQuickEventsGiveOneJob(): void
    {
        $this->reviewOfClonedRepository();
        $this->storePullRequest();
        $first = $this->openRequest(3);
        $second = $this->openRequest(7);
        $this->claimAndLink($first, $second);
        self::runCheckRunJobs();
        $jobsBefore = self::sentCheckRunJobs();

        self::commands()->markReadyForReview(new MarkReadyForReview($first, 'Totals use Money now.'), $this->agent());
        self::commands()->markReadyForReview(new MarkReadyForReview($second, 'Rounding uses Money now.'), $this->agent());

        self::assertSame(1, self::sentCheckRunJobs() - $jobsBefore);
    }

    /**
     * A tenant with installation 5551 and the clone of acme/billing, whose main has src/Invoice.php, and a review of it.
     *
     * @param array<string, string> $permissions The accepted permissions of installation 5551
     */
    private function reviewOfClonedRepository(array $permissions = self::PERMISSIONS): void
    {
        self::mockTime('2026-10-13 09:00:00');
        $this->useLocalGitHub();
        $source = $this->gitHubSource();
        $this->head = CommitSha::fromString($source->commit([self::PATH => implode('', array_map(static fn (int $n): string => "    \$total += {$n};\n", range(1, 120)))]));
        $source->git('push', '--quiet', 'origin', 'main');
        $this->user = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create();
        self::storeInstallation($this->user->getTenantId(), permissions: $permissions);
        self::storeCandidate($this->user->getTenantId());
        $this->repository = self::connectGitHub($this->user->getTenantId());
        self::runQueuedMessages();
        $this->reviewId = self::getContainer()->get(ReviewCommands::class)->start($this->repository->getId(), RefName::fromString('main'), 'Billing totals', $this->userActor());
        self::runQueuedMessages();
    }

    /**
     * As a read of pull request 42 at the head of main left it, newer than pull-request-acme-billing-42.json.
     */
    /**
     * @param string $state The GitHub `state`
     */
    private function storePullRequest(string $state = 'open'): PullRequest
    {
        $repository = self::getContainer()->get(RepositoryRepository::class)->findFresh($this->repository->getId()) ?? self::fail('The repository is gone.');
        $pullRequest = new PullRequest($repository, 42);
        $pullRequest->record('Use Money for invoice totals', $state, false, false, $this->head, 'acme/billing', new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($pullRequest);
        $entityManager->flush();
        $entityManager->clear();

        return $pullRequest;
    }

    /**
     * @param int|null $line Of src/Invoice.php at the head, null for a general request
     */
    private function openRequest(?int $line, string $body = self::BODY): RequestId
    {
        $anchor = null === $line ? null : $this->lineAnchor($line);
        [$opened] = self::commands()->open($this->reviewId, new Open($anchor, $body), $this->userActor());

        return $opened->requestId ?? self::fail('request.opened has no requestId.');
    }

    /**
     * In one transaction, as a batch of drafts opens them, so the location recompute runs once, not once per request.
     *
     * @param list<int> $lines Of src/Invoice.php at the head
     *
     * @return list<RequestId>
     */
    private function openRequestsAtOnce(array $lines): array
    {
        $opened = self::getContainer()->get(CommandExecutor::class)->execute($this->reviewId, $this->userActor(), function () use ($lines): array {
            $review = self::getContainer()->get(ReviewRepository::class)->findFresh($this->reviewId) ?? self::fail('The review is gone.');

            return array_merge(...array_map(fn (int $line): array => RequestDecider::decide(new Open($this->lineAnchor($line), self::BODY), [], $review->toSnapshot(), $this->userActor()), $lines));
        });

        return array_map(static fn (RecordedEvent $event): RequestId => $event->requestId ?? self::fail('request.opened has no requestId.'), $opened);
    }

    private function lineAnchor(int $line): Anchor
    {
        $reader = self::getContainer()->get(GitReader::class);
        $clonePath = self::clonePath($this->repository);
        $entry = $reader->entryAt($clonePath, $this->head, RepoPath::fromString(self::PATH)) ?? self::fail('The head has no '.self::PATH.'.');
        $blob = $reader->readBlob($clonePath, BlobSha::fromString($entry->objectId)) ?? self::fail('The clone has no blob of '.self::PATH.'.');
        $range = LineRange::of($line, $line);

        return Anchor::of(AnchorKind::Line, RepoPath::fromString(self::PATH), $this->head, $blob->sha, $range, null, SnapshotCapture::capture($blob->bytes, $range));
    }

    private function claimAndLink(RequestId ...$requestIds): void
    {
        foreach ($requestIds as $requestId) {
            self::commands()->claim(new Claim($requestId), $this->agent());
        }

        foreach (array_chunk($requestIds, 50) as $linked) {
            self::commands()->linkPullRequest(self::URL, $linked, null, $this->agent());
        }
    }

    /**
     * Past the debounce delay, so the queued check run jobs run.
     */
    private static function runCheckRunJobs(): void
    {
        Clock::get()->sleep(QueueCheckRun::DELAY_MILLISECONDS / 1000 + 1);
        self::runQueuedMessages();
    }

    /**
     * @return list<array{string, string, array<mixed>}>
     */
    private static function checkRunCalls(): array
    {
        return self::getContainer()->get(GitHubResponses::class)->checkRunCalls();
    }

    private static function sentCheckRunJobs(): int
    {
        return \count(array_filter(self::asyncTransport()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof CheckRunJob));
    }

    private static function gitHubRequestCount(): int
    {
        return self::getContainer()->get('github.client.mock_transport')->getRequestsCount();
    }

    private static function commands(): RequestCommands
    {
        return self::getContainer()->get(RequestCommands::class);
    }

    private function userActor(): Actor
    {
        return Actor::user($this->user->getId());
    }

    private function agent(): Actor
    {
        return Actor::agent($this->user->getId(), 'claude-code laptop');
    }
}
