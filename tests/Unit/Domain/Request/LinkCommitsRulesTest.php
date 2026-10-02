<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Error\InvalidTransition;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\RequestDecider;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LinkCommitsRulesTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Skips pairs that are already linked and writes no event when nothing is new')]
    public function testSkipsLinkedPairs(): void
    {
        $agent = RequestScenario::claimingAgent();
        $scenario = RequestScenario::inState('in_progress');
        $linked = $scenario->requestId();
        $unlinked = $scenario->open();
        $scenario->run(new Claim($unlinked), $agent);
        $command = new LinkCommits(CommitSha::fromString(RequestScenario::LINKED_COMMIT), [$linked, $unlinked]);

        $events = $scenario->run($command, $agent);

        self::assertCount(1, $events);
        $event = $events[0]->event;
        self::assertInstanceOf(CommitLinkedEvent::class, $event);
        self::assertEquals([$unlinked], $event->requestIds);
        self::assertSame([], $scenario->decide($command, $agent));
    }

    #[TestDox('A request that the caller does not hold gives invalid_transition and writes nothing')]
    public function testRequestHeldByAnotherAgentRefusesTheWholeLink(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $held = $scenario->requestId();
        $heldByOther = $scenario->open();
        $scenario->run(new Claim($heldByOther), RequestScenario::otherAgent());

        try {
            $scenario->decide(new LinkCommits(CommitSha::fromString(RequestScenario::OTHER_COMMIT), [$held, $heldByOther]), RequestScenario::claimingAgent());
        } catch (InvalidTransition $error) {
            self::assertTrue($heldByOther->equals($error->details()['requestId']));

            return;
        }

        self::fail('No InvalidTransition was thrown.');
    }

    #[TestDox('Requests of another review give invalid_input')]
    public function testRequestsOfAnotherReviewAreRejected(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $otherReview = RequestScenario::inState('in_progress');
        $command = new LinkCommits(CommitSha::fromString(RequestScenario::OTHER_COMMIT), [$scenario->requestId(), $otherReview->requestId()]);
        $states = [$scenario->state(), $otherReview->state()];

        self::assertInvalidInput(['requestIds.1'], static fn () => RequestDecider::decide($command, $states, $scenario->review(), RequestScenario::claimingAgent()));
    }

    #[TestDox('Unlinking a commit that is not linked gives invalid_input')]
    public function testUnlinkingAnUnlinkedCommitIsRejected(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $command = new UnlinkCommit($scenario->requestId(), CommitSha::fromString(RequestScenario::OTHER_COMMIT));

        self::assertInvalidInput(['commitSha'], static fn () => $scenario->decide($command, RequestScenario::claimingAgent()));
    }
}
