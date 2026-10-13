<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Architecture section 6 and docs/specs/rest-api-requests.md section 1. Each state has one available commit link and one
 * pull request link, and the claiming agent holds the in_progress request. Inputs are valid: link_commits names a new
 * commit, unlink_commit the linked one, link_pull_request a new pull request of the review's GitHub repository,
 * unlink_pull_request the linked one, a proposal decision the current proposal, or a new ID when none is pending, and
 * set_plan_requirement the other value. A pending suggestion has no links, so its unlink input is invalid.
 */
final class TransitionMatrixTest extends KernelTestCase
{
    private const string T = 'invalid_transition';
    private const string I = 'invalid_input';
    private const string COMMENTED = 'request.commented';
    private const string CLAIMED = 'request.claimed';
    private const string RELEASED = 'request.released';
    private const string LINKED = 'commit.linked';
    private const string UNLINKED = 'commit.unlinked';
    private const string PULL_REQUEST_LINKED = 'pull_request.linked';
    private const string PULL_REQUEST_UNLINKED = 'pull_request.unlinked';
    private const string READY = 'request.ready_for_review';
    private const string WONT_DO = 'request.wont_do';
    private const string VERIFIED = 'request.verified';
    private const string REOPENED = 'request.reopened';
    private const string PROPOSED = 'request.proposed';
    private const string APPROVED = 'request.proposal_approved';
    private const string REJECTED = 'request.proposal_rejected';
    private const string PLAN_REQUIRED = 'request.plan_required';
    private const string PLAN_WAIVED = 'request.plan_waived';
    private const string ACCEPTED = 'request.suggestion_accepted';
    private const string DISMISSED = 'request.suggestion_dismissed';

    /**
     * Columns: open, in_progress, ready_for_review, verified, reopened, wont_do by agent, wont_do by user, then the
     * in_progress states proposal pending, proposal rejected, plan required, and plan approved, then suggestion pending.
     */
    private const array MATRIX = [
        'reply' => [
            'user' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
            'claiming agent' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
            'another agent' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
        ],
        'claim' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::CLAIMED, 'no event', self::T, self::T, self::CLAIMED, self::T, self::T, 'no event', 'no event', 'no event', 'no event', self::T],
            'another agent' => [self::CLAIMED, self::T, self::T, self::T, self::CLAIMED, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'release' => [
            'user' => [self::T, self::RELEASED, self::T, self::T, self::T, self::T, self::T, self::RELEASED, self::RELEASED, self::RELEASED, self::RELEASED, self::T],
            'claiming agent' => [self::T, self::RELEASED, self::T, self::T, self::T, self::T, self::T, self::RELEASED, self::RELEASED, self::RELEASED, self::RELEASED, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'link_commits' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::LINKED, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::LINKED, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'unlink_commit' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::I],
            'claiming agent' => [self::T, self::UNLINKED, self::T, self::T, self::T, self::T, self::T, self::UNLINKED, self::UNLINKED, self::UNLINKED, self::UNLINKED, self::I],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::I],
        ],
        'link_pull_request' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::PULL_REQUEST_LINKED, self::PULL_REQUEST_LINKED, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::PULL_REQUEST_LINKED, self::T],
            'another agent' => [self::T, self::T, self::PULL_REQUEST_LINKED, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'unlink_pull_request' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::I],
            'claiming agent' => [self::T, self::PULL_REQUEST_UNLINKED, self::PULL_REQUEST_UNLINKED, self::T, self::T, self::T, self::T, self::PULL_REQUEST_UNLINKED, self::PULL_REQUEST_UNLINKED, self::PULL_REQUEST_UNLINKED, self::PULL_REQUEST_UNLINKED, self::I],
            'another agent' => [self::T, self::T, self::PULL_REQUEST_UNLINKED, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::I],
        ],
        'mark_ready_for_review' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::READY, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::READY, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'mark_wont_do' => [
            'user' => [self::WONT_DO, self::WONT_DO, self::T, self::T, self::WONT_DO, self::T, self::T, self::WONT_DO, self::WONT_DO, self::WONT_DO, self::WONT_DO, self::T],
            'claiming agent' => [self::WONT_DO, self::WONT_DO, self::T, self::T, self::WONT_DO, self::T, self::T, self::WONT_DO, self::WONT_DO, self::WONT_DO, self::WONT_DO, self::T],
            'another agent' => [self::WONT_DO, self::T, self::T, self::T, self::WONT_DO, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'verify' => [
            'user' => [self::T, self::T, self::VERIFIED, self::T, self::T, self::VERIFIED, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'reopen' => [
            'user' => [self::T, self::T, self::REOPENED, self::REOPENED, self::T, self::REOPENED, self::REOPENED, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'propose_solution' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::PROPOSED, self::T, self::T, self::T, self::T, self::T, self::PROPOSED, self::PROPOSED, self::PROPOSED, self::PROPOSED, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'approve_proposal' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::APPROVED, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'reject_proposal' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::REJECTED, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'set_plan_requirement' => [
            'user' => [self::PLAN_REQUIRED, self::PLAN_REQUIRED, self::T, self::T, self::PLAN_REQUIRED, self::T, self::T, self::PLAN_REQUIRED, self::PLAN_REQUIRED, self::PLAN_WAIVED, self::PLAN_WAIVED, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'accept_suggestion' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::ACCEPTED],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'dismiss_suggestion' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::DISMISSED],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
    ];

    #[TestDox('Command $command by $caller on $state gives $outcome')]
    #[DataProvider('cases')]
    public function testTransition(string $command, string $caller, string $state, string $outcome): void
    {
        $scenario = 'none' === $state ? new RequestScenario() : RequestScenario::inState($state);

        self::assertSame($outcome, $scenario->outcome($scenario->validCommand($command), RequestScenario::caller($caller)));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function cases(): iterable
    {
        $openOutcomes = ['user' => 'request.opened', 'claiming agent' => 'LogicException', 'another agent' => 'LogicException'];
        $suggestOutcomes = ['user' => 'LogicException', 'claiming agent' => 'request.opened', 'another agent' => 'request.opened'];

        foreach ($openOutcomes as $caller => $outcome) {
            yield "open by {$caller} on none" => ['open', $caller, 'none', $outcome];
        }

        foreach ($suggestOutcomes as $caller => $outcome) {
            yield "suggest by {$caller} on none" => ['suggest', $caller, 'none', $outcome];
        }

        foreach (self::MATRIX as $command => $rows) {
            foreach ($rows as $caller => $outcomes) {
                foreach (RequestScenario::STATES as $column => $state) {
                    yield "{$command} by {$caller} on {$state}" => [$command, $caller, $state, $outcomes[$column]];
                }
            }
        }
    }
}
