<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Domain\Actor\Actor;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\EventType;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Command\ApproveProposal;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\ProposeSolution;
use App\Domain\Request\Command\RejectProposal;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\SetPlanRequirement;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\Verify;
use App\Domain\Review\Command\CloseReview;
use App\EventStore\EventsCommitted;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\EventStore\CommandFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectionConsistencyTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    private const string FOUND_COMMIT = 'c3d4e5f60718293a4b5c6d7e8f9012345678a1b2';

    #[TestDox('A scenario with every event type gives the same projection snapshot before and after a rebuild, including a commit that is available at link time')]
    public function testRebuildReproducesTheProjections(): void
    {
        $tenantId = self::enterNewTenant();
        $reviewId = self::startReview(self::newRepository($tenantId));
        $declined = self::declinedRequest($reviewId, self::fixedRequest($reviewId));
        self::submitBatch($reviewId, $declined);
        self::reviewCommands()->close($reviewId, new CloseReview('Shipped in 2.4'), RequestScenario::user());

        $connection = self::getContainer()->get(Connection::class);
        $tenant = [self::storedRows($tenantId)['tenant'][0]['id']];
        $connection->executeStatement("UPDATE review SET index_state = 'ready', files_total = 12, files_done = 12, symbols_total = 40 WHERE tenant_id = ?", $tenant);
        $before = self::storedRows($tenantId);

        self::assertEqualsCanonicalizing(array_column(EventType::cases(), 'value'), array_values(array_unique(array_column($before['review_event'], 'type'))));
        $linkedAvailable = array_find($before['commit_link'], static fn (array $link): bool => RequestScenario::LINKED_COMMIT === $link['commit_sha']) ?? self::fail('The available link is missing.');
        self::assertSame(['available', $linkedAvailable['linked_at']], [$linkedAvailable['state'], $linkedAvailable['found_at']]);

        $connection->executeStatement("UPDATE review SET title = 'Tampered', last_event_seq = 0 WHERE tenant_id = ?", $tenant);
        $connection->executeStatement("UPDATE change_request SET status = 'open', body = 'Tampered', awaiting = 'none', last_event_seq = 0, require_approval = false, proposal_state = NULL, pull_request_urls = '[]' WHERE tenant_id = ?", $tenant);
        $connection->executeStatement("UPDATE commit_link SET state = 'pending', found_at = NULL WHERE tenant_id = ?", $tenant);
        $connection->executeStatement('DELETE FROM comment WHERE tenant_id = ?', $tenant);

        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:projections:rebuild'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame($before, self::storedRows($tenantId));
    }

    /**
     * Opened with requireApproval. Claim, a rejected and an approved proposal, links (available, pending and unlinked,
     * pending and found), pull request links (linked and unlinked, linked), ready, verify, reopen, and the plan waived.
     */
    private static function fixedRequest(ReviewId $reviewId): RequestId
    {
        $commands = self::requestCommands();
        $agent = RequestScenario::claimingAgent();
        $availableCommit = RequestScenario::commit(RequestScenario::LINKED_COMMIT);
        $wrongCommit = CommitSha::fromString(RequestScenario::OTHER_COMMIT);
        $foundCommit = CommitSha::fromString(self::FOUND_COMMIT);
        $requestId = self::openRequest($reviewId, requireApproval: true);
        $user = RequestScenario::user();

        $commands->claim(new Claim($requestId), $agent);
        [$rejected] = $commands->propose(new ProposeSolution($requestId, 'Round per invoice.'), $agent);
        $commands->rejectProposal(new RejectProposal($requestId, $rejected->id, 'Round per line.'), $user);
        [$approved] = $commands->propose(new ProposeSolution($requestId, 'Round per line.', "diff --git a/src/Billing/InvoiceTotals.php b/src/Billing/InvoiceTotals.php\n"), $agent);
        $commands->approveProposal(new ApproveProposal($requestId, $approved->id), $user);
        self::executor()->execute($reviewId, $agent, static fn (): array => [
            new DecidedEvent(new CommitLinkedEvent($availableCommit->commitSha, [$requestId], null, true, $availableCommit->subject, $availableCommit->committedAt)),
        ]);
        $commands->linkCommits(new LinkCommits($wrongCommit, [$requestId], 'Wrong commit.'), $agent);
        $commands->unlinkCommit(new UnlinkCommit($requestId, $wrongCommit), $agent);
        $commands->linkCommits(new LinkCommits($foundCommit, [$requestId]), $agent);
        self::executor()->execute($reviewId, Actor::system(), static fn (): array => [
            new DecidedEvent(new CommitFoundEvent($foundCommit, [$requestId], 'Extract TaxRounding', new \DateTimeImmutable('2026-09-29T07:50:00Z'))),
        ]);
        $commands->linkPullRequest(RequestScenario::LINKED_PULL_REQUEST, [$requestId], 'Wrong pull request.', $agent);
        $commands->linkPullRequest(RequestScenario::PULL_REQUEST, [$requestId], 'Opened for review.', $agent);
        $commands->unlinkPullRequest($requestId, RequestScenario::LINKED_PULL_REQUEST, $agent);
        $commands->markReadyForReview(new MarkReadyForReview($requestId, 'Extracted TaxRounding.'), $agent);
        $commands->verify(new Verify($requestId), RequestScenario::user());
        $commands->reopen(new Reopen($requestId, 'Also cover the shipping totals.'), RequestScenario::user());
        $commands->setPlanRequirement(new SetPlanRequirement($requestId, false), $user);

        return $requestId;
    }

    /**
     * A question, a reply, the plan required, claim, release, and wont_do by the agent.
     */
    private static function declinedRequest(ReviewId $reviewId, RequestId $fixed): RequestId
    {
        $commands = self::requestCommands();
        $agent = RequestScenario::claimingAgent();
        $requestId = self::openRequest($reviewId);

        $commands->reply(new Reply($requestId, 'Should this cover the shipping totals?', true), $agent);
        $commands->reply(new Reply($requestId, 'Yes, see '.$fixed.'.'), RequestScenario::user());
        $commands->setPlanRequirement(new SetPlanRequirement($requestId, true), RequestScenario::user());
        $commands->claim(new Claim($requestId), $agent);
        $commands->release(new Release($requestId, 'Needs a decision first.'), $agent);
        $commands->markWontDo(new MarkWontDo($requestId, RequestScenario::REASON), $agent);

        return $requestId;
    }

    /**
     * The events of a draft submission: a new request, a reply, and batch.submitted in one transaction.
     */
    private static function submitBatch(ReviewId $reviewId, RequestId $replyTo): void
    {
        $batchId = BatchId::generate();
        $openedId = RequestId::generate();
        $commentId = CommentId::generate();
        /** @var \ArrayObject<int, EventsCommitted> $committed */
        $committed = new \ArrayObject();
        self::getContainer()->get('event_dispatcher')->addListener(EventsCommitted::class, static function (EventsCommitted $event) use ($committed): void {
            $committed[] = $event;
        });

        self::executor()->execute($reviewId, RequestScenario::user(), static fn (): array => [
            new DecidedEvent(new RequestOpenedEvent(new RequestScenario()->anchor(), 'Round per line, not per invoice.', $batchId), $openedId),
            new DecidedEvent(new RequestCommentedEvent($commentId, 'Please reconsider.', false, $batchId), $replyTo),
            new DecidedEvent(new BatchSubmittedEvent($batchId, 'Second pass.', [$replyTo, $openedId], [$commentId])),
        ]);

        self::assertEquals([$openedId, $replyTo], $committed[0]->requestIds ?? self::fail('Nothing was dispatched.'));
    }
}
