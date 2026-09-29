<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Identity\UserId;
use App\Domain\Request\Action;
use App\Domain\Request\AllowedActions;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\ReviewSnapshot;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A property test over every state shape, caller, and action, with valid input and available links.
 */
final class AllowedActionsTest extends KernelTestCase
{
    private const string OTHER_USER_ID = '01K6AZZ9Y8X7W6V5T4S3R2Q1P1';
    private const string EVENTS = 'events';
    private const string NO_EVENT = 'no event';

    #[TestDox('An action is allowed if and only if decide() throws neither invalid_transition nor review_closed, except a repeated claim by the holder')]
    public function testAnActionIsAllowedIfAndOnlyIfTheDeciderAcceptsIt(): void
    {
        $mismatches = [];
        foreach (['open' => LifecycleFixtures::openReview(), 'closed' => LifecycleFixtures::closedReview()] as $reviewName => $review) {
            foreach (self::states() as $stateName => $state) {
                foreach (self::callers() as $callerName => $caller) {
                    $allowedActions = AllowedActions::forCaller($state, $review, $caller);
                    foreach (Action::cases() as $action) {
                        $allowed = \in_array($action, $allowedActions, true);
                        $outcome = self::outcome($action, $state, $review, $caller);
                        [$expectedAllowed, $expectedOutcome] = self::expected($action, $state, $review, $caller, $allowed);
                        if ([$expectedAllowed, $expectedOutcome] !== [$allowed, $outcome]) {
                            $mismatches[] = \sprintf(
                                '%s by %s on %s in the %s review: allowed %s, decide() gives %s; expected allowed %s with %s',
                                $action->value, $callerName, $stateName, $reviewName, self::yesNo($allowed), $outcome, self::yesNo($expectedAllowed), $expectedOutcome,
                            );
                        }
                    }
                }
            }
        }

        self::assertSame([], $mismatches);
    }

    #[TestDox('The list equals the docs/specs table for agent and user callers')]
    public function testTheListEqualsTheDocsTable(): void
    {
        $mismatches = [];
        foreach (self::states() as $stateName => $state) {
            foreach (self::callers() as $callerName => $caller) {
                $expected = SpecAllowedActions::for($state, $caller);
                $actual = AllowedActions::forCaller($state, LifecycleFixtures::openReview(), $caller);
                if ($expected !== $actual) {
                    $mismatches[] = \sprintf('%s on %s: expected [%s], got [%s]', $callerName, $stateName, self::names($expected), self::names($actual));
                }
            }
        }

        self::assertSame([], $mismatches);
    }

    #[TestDox('The list is empty on a closed review')]
    public function testTheListIsEmptyOnAClosedReview(): void
    {
        $nonEmpty = [];
        foreach (self::states() as $stateName => $state) {
            foreach (self::callers() as $callerName => $caller) {
                $actions = AllowedActions::forCaller($state, LifecycleFixtures::closedReview(), $caller);
                if ([] !== $actions) {
                    $nonEmpty[] = \sprintf('%s on %s: [%s]', $callerName, $stateName, self::names($actions));
                }
            }
        }

        self::assertSame([], $nonEmpty);
    }

    /**
     * An allowed action gives its events. A refused one gives review_closed on a closed review, else
     * invalid_transition, because review_closed comes first in the check order of docs/specs/README.md section 4.
     *
     * @return array{bool, string} whether the action must be allowed, and the outcome of decide()
     */
    private static function expected(Action $action, RequestState $state, ReviewSnapshot $review, Actor $caller, bool $allowed): array
    {
        if (Action::UnlinkCommit === $action && [] === $state->links) {
            // No valid input exists: unlink_commit on a commit that is not linked gives invalid_input, which comes first.
            return [false, ErrorCode::InvalidInput->value];
        }
        if (Action::Claim === $action && !$review->isClosed() && null !== $state->claimedBy && SpecAllowedActions::sameActor($state->claimedBy, $caller)) {
            return [false, self::NO_EVENT];
        }
        if ($allowed) {
            return [true, self::EVENTS];
        }

        return [false, ($review->isClosed() ? ErrorCode::ReviewClosed : ErrorCode::InvalidTransition)->value];
    }

    private static function outcome(Action $action, RequestState $state, ReviewSnapshot $review, Actor $caller): string
    {
        try {
            $decided = RequestDecider::decide(ValidCommands::forAction($action, $state->requestId), [$state], $review, $caller, ValidCommands::availability());
        } catch (DomainError $error) {
            return $error->code()->value;
        } catch (\LogicException $exception) {
            return 'LogicException: '.$exception->getMessage();
        }

        return [] === $decided ? self::NO_EVENT : self::EVENTS;
    }

    /**
     * @return array<string, RequestState>
     */
    private static function states(): array
    {
        $states = [];
        foreach (['without links' => [], 'with an available link' => [ValidCommands::linkedCommit()]] as $linksName => $links) {
            foreach ([RequestStatus::Open, RequestStatus::Reopened, RequestStatus::ReadyForReview] as $status) {
                $states[$status->value.' '.$linksName] = LifecycleFixtures::state($status, links: $links);
            }
            foreach ([RequestStatus::Open, RequestStatus::Reopened] as $statusBeforeClaim) {
                foreach ([LifecycleFixtures::agent(), LifecycleFixtures::otherAgent()] as $holder) {
                    $name = \sprintf('in_progress from %s held by %s %s', $statusBeforeClaim->value, $holder->agentName, $linksName);
                    $states[$name] = LifecycleFixtures::state(RequestStatus::InProgress, claimedBy: $holder, links: $links, statusBeforeClaim: $statusBeforeClaim);
                }
            }
            foreach ([RequestStatus::ReadyForReview, RequestStatus::WontDo] as $verifiedFrom) {
                $states['verified from '.$verifiedFrom->value.' '.$linksName] = LifecycleFixtures::state(RequestStatus::Verified, links: $links, verifiedFrom: $verifiedFrom);
            }
            foreach ([ActorKind::Agent, ActorKind::User] as $wontDoSetBy) {
                $states['wont_do set by '.$wontDoSetBy->value.' '.$linksName] = LifecycleFixtures::state(RequestStatus::WontDo, wontDoSetBy: $wontDoSetBy, links: $links);
            }
        }

        return $states;
    }

    /**
     * The last caller has the agent name of {@see LifecycleFixtures::agent()} but acts for another user, so it is another agent.
     *
     * @return array<string, Actor>
     */
    private static function callers(): array
    {
        return [
            'the user' => LifecycleFixtures::user(),
            'agent claude-code laptop' => LifecycleFixtures::agent(),
            'agent codex desktop' => LifecycleFixtures::otherAgent(),
            'agent claude-code laptop of another user' => Actor::agent(UserId::fromString(self::OTHER_USER_ID), LifecycleFixtures::AGENT_NAME),
        ];
    }

    /**
     * @param list<Action> $actions
     */
    private static function names(array $actions): string
    {
        return implode(', ', array_map(static fn (Action $action): string => $action->value, $actions));
    }

    private static function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
