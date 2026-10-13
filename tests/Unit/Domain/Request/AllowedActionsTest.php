<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\Action;
use App\Domain\Request\AllowedActions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AllowedActionsTest extends KernelTestCase
{
    private const array CALLERS = ['user', 'claiming agent', 'another agent'];

    /**
     * Valid input and available links. A state without links has no valid unlink_commit or unlink_pull_request input, so
     * those actions are left out there.
     */
    #[TestDox('An action is allowed if and only if decide() throws neither invalid_transition nor review_closed, except a repeated claim by the holder')]
    #[DataProvider('combinations')]
    public function testDeciderAgreesWithAllowedActions(string $state, bool $linked, string $caller, bool $reviewClosed): void
    {
        $scenario = RequestScenario::inState($state, $linked);

        if ($reviewClosed) {
            $scenario->closeReview();
        }

        $actor = RequestScenario::caller($caller);
        $allowedActions = AllowedActions::for($scenario->state(), $scenario->review(), $actor);

        foreach (Action::cases() as $action) {
            if (in_array($action, [Action::UnlinkCommit, Action::UnlinkPullRequest], true) && !$linked) {
                continue;
            }

            $outcome = $scenario->outcome($scenario->validCommand($action->value), $actor);
            $allowed = in_array($action, $allowedActions, true);
            $refused = in_array($outcome, ['invalid_transition', 'review_closed'], true);
            $repeatedClaim = Action::Claim === $action && !$reviewClosed && $scenario->state()->isClaimedBy($actor);

            if ($repeatedClaim) {
                self::assertSame('no event', $outcome, $action->value);
                self::assertFalse($allowed, $action->value);
            } else {
                self::assertSame($allowed, !$refused, "{$action->value} gives {$outcome}");
                self::assertTrue($refused || 1 === preg_match('/^(request|commit|pull_request)\./', $outcome), "{$action->value} gives {$outcome}");
            }
        }
    }

    /**
     * @return iterable<string, array{string, bool, string, bool}>
     */
    public static function combinations(): iterable
    {
        foreach (RequestScenario::STATES as $state) {
            foreach ('suggestion pending' === $state ? [false] : [true, false] as $linked) {
                foreach (self::CALLERS as $caller) {
                    foreach ([false, true] as $reviewClosed) {
                        $name = sprintf('%s %s, %s, review %s', $state, $linked ? 'with a link' : 'without links', $caller, $reviewClosed ? 'closed' : 'open');

                        yield $name => [$state, $linked, $caller, $reviewClosed];
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $expected
     */
    #[TestDox('The list equals the docs/specs table for agent and user callers')]
    #[DataProvider('table')]
    public function testListEqualsTheSpecsTable(string $state, bool $linked, string $caller, array $expected): void
    {
        $scenario = RequestScenario::inState($state, $linked);
        $allowedActions = AllowedActions::for($scenario->state(), $scenario->review(), RequestScenario::caller($caller));

        self::assertSame($expected, array_map(static fn (Action $action): string => $action->value, $allowedActions));
    }

    /**
     * docs/specs/rest-api-requests.md section 1. The claiming agent holds the in_progress request.
     *
     * @return iterable<string, array{string, bool, string, list<string>}>
     */
    public static function table(): iterable
    {
        $rows = [
            ['open', true, ['reply', 'mark_wont_do', 'set_plan_requirement'], ['reply', 'claim', 'mark_wont_do'], ['reply', 'claim', 'mark_wont_do']],
            ['in_progress', true, ['reply', 'release', 'mark_wont_do', 'set_plan_requirement'], ['reply', 'release', 'link_commits', 'unlink_commit', 'link_pull_request', 'unlink_pull_request', 'mark_ready_for_review', 'mark_wont_do', 'propose_solution'], ['reply']],
            ['in_progress', false, ['reply', 'release', 'mark_wont_do', 'set_plan_requirement'], ['reply', 'release', 'link_commits', 'link_pull_request', 'mark_ready_for_review', 'mark_wont_do', 'propose_solution'], ['reply']],
            ['ready_for_review', true, ['reply', 'verify', 'reopen'], ['reply', 'link_pull_request', 'unlink_pull_request'], ['reply', 'link_pull_request', 'unlink_pull_request']],
            ['verified', true, ['reply', 'reopen'], ['reply'], ['reply']],
            ['reopened', true, ['reply', 'mark_wont_do', 'set_plan_requirement'], ['reply', 'claim', 'mark_wont_do'], ['reply', 'claim', 'mark_wont_do']],
            ['wont_do by agent', true, ['reply', 'verify', 'reopen'], ['reply'], ['reply']],
            ['wont_do by user', true, ['reply', 'reopen'], ['reply'], ['reply']],
            ['proposal pending', true, ['reply', 'release', 'mark_wont_do', 'approve_proposal', 'reject_proposal', 'set_plan_requirement'], ['reply', 'release', 'unlink_commit', 'unlink_pull_request', 'mark_wont_do', 'propose_solution'], ['reply']],
            ['proposal pending', false, ['reply', 'release', 'mark_wont_do', 'approve_proposal', 'reject_proposal', 'set_plan_requirement'], ['reply', 'release', 'mark_wont_do', 'propose_solution'], ['reply']],
            ['proposal rejected', true, ['reply', 'release', 'mark_wont_do', 'set_plan_requirement'], ['reply', 'release', 'unlink_commit', 'unlink_pull_request', 'mark_wont_do', 'propose_solution'], ['reply']],
            ['plan required', true, ['reply', 'release', 'mark_wont_do', 'set_plan_requirement'], ['reply', 'release', 'unlink_commit', 'unlink_pull_request', 'mark_wont_do', 'propose_solution'], ['reply']],
            ['plan approved', true, ['reply', 'release', 'mark_wont_do', 'set_plan_requirement'], ['reply', 'release', 'link_commits', 'unlink_commit', 'link_pull_request', 'unlink_pull_request', 'mark_ready_for_review', 'mark_wont_do', 'propose_solution'], ['reply']],
            ['suggestion pending', false, ['reply', 'accept_suggestion', 'dismiss_suggestion'], ['reply'], ['reply']],
        ];

        foreach ($rows as [$state, $linked, $user, $claimingAgent, $otherAgent]) {
            $suffix = $linked ? '' : ' without links';

            yield "{$state}{$suffix}, user" => [$state, $linked, 'user', $user];
            yield "{$state}{$suffix}, claiming agent" => [$state, $linked, 'claiming agent', $claimingAgent];
            yield "{$state}{$suffix}, another agent" => [$state, $linked, 'another agent', $otherAgent];
        }
    }

    #[TestDox('The list is empty on a closed review')]
    #[DataProvider('states')]
    public function testListIsEmptyOnAClosedReview(string $state): void
    {
        $scenario = RequestScenario::inState($state);
        $scenario->closeReview();

        foreach (self::CALLERS as $caller) {
            self::assertSame([], AllowedActions::for($scenario->state(), $scenario->review(), RequestScenario::caller($caller)), $caller);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function states(): iterable
    {
        foreach (RequestScenario::STATES as $state) {
            yield $state => [$state];
        }
    }
}
