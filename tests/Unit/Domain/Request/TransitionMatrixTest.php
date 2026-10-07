<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Architecture section 6 and docs/specs/rest-api-requests.md section 1. Each state has one available link, and the claiming agent
 * holds the in_progress request. Inputs are valid: link_commits names a new commit, unlink_commit the linked one,
 * link_pull_request a pull request of the review's GitHub repository, and a proposal decision the current proposal,
 * or a new ID when none is pending.
 */
final class TransitionMatrixTest extends KernelTestCase
{
    private const string T = 'invalid_transition';
    private const string COMMENTED = 'request.commented';
    private const string CLAIMED = 'request.claimed';
    private const string RELEASED = 'request.released';
    private const string LINKED = 'commit.linked';
    private const string UNLINKED = 'commit.unlinked';
    private const string PULL_REQUEST_LINKED = 'pull_request.linked';
    private const string READY = 'request.ready_for_review';
    private const string WONT_DO = 'request.wont_do';
    private const string VERIFIED = 'request.verified';
    private const string REOPENED = 'request.reopened';
    private const string PROPOSED = 'request.proposed';
    private const string APPROVED = 'request.proposal_approved';
    private const string REJECTED = 'request.proposal_rejected';

    /**
     * Columns: open, in_progress, ready_for_review, verified, reopened, wont_do by agent, wont_do by user, then the
     * in_progress states proposal pending, proposal rejected, plan required, and plan approved.
     */
    private const array MATRIX = [
        'reply' => [
            'user' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
            'claiming agent' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
            'another agent' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
        ],
        'claim' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::CLAIMED, 'no event', self::T, self::T, self::CLAIMED, self::T, self::T, 'no event', 'no event', 'no event', 'no event'],
            'another agent' => [self::CLAIMED, self::T, self::T, self::T, self::CLAIMED, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'release' => [
            'user' => [self::T, self::RELEASED, self::T, self::T, self::T, self::T, self::T, self::RELEASED, self::RELEASED, self::RELEASED, self::RELEASED],
            'claiming agent' => [self::T, self::RELEASED, self::T, self::T, self::T, self::T, self::T, self::RELEASED, self::RELEASED, self::RELEASED, self::RELEASED],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'link_commits' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::LINKED, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::LINKED],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'unlink_commit' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::UNLINKED, self::T, self::T, self::T, self::T, self::T, self::UNLINKED, self::UNLINKED, self::UNLINKED, self::UNLINKED],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'link_pull_request' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::PULL_REQUEST_LINKED, self::PULL_REQUEST_LINKED, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::PULL_REQUEST_LINKED],
            'another agent' => [self::T, self::T, self::PULL_REQUEST_LINKED, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'mark_ready_for_review' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::READY, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::READY],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'mark_wont_do' => [
            'user' => [self::WONT_DO, self::WONT_DO, self::T, self::T, self::WONT_DO, self::T, self::T, self::WONT_DO, self::WONT_DO, self::WONT_DO, self::WONT_DO],
            'claiming agent' => [self::WONT_DO, self::WONT_DO, self::T, self::T, self::WONT_DO, self::T, self::T, self::WONT_DO, self::WONT_DO, self::WONT_DO, self::WONT_DO],
            'another agent' => [self::WONT_DO, self::T, self::T, self::T, self::WONT_DO, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'verify' => [
            'user' => [self::T, self::T, self::VERIFIED, self::T, self::T, self::VERIFIED, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'reopen' => [
            'user' => [self::T, self::T, self::REOPENED, self::REOPENED, self::T, self::REOPENED, self::REOPENED, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'propose_solution' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::PROPOSED, self::T, self::T, self::T, self::T, self::T, self::PROPOSED, self::PROPOSED, self::PROPOSED, self::PROPOSED],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'approve_proposal' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::APPROVED, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'reject_proposal' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::REJECTED, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T, self::T],
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

        foreach ($openOutcomes as $caller => $outcome) {
            yield "open by {$caller} on none" => ['open', $caller, 'none', $outcome];
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
