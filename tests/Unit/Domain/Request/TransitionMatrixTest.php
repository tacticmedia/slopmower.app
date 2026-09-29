<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Event\DecidedEvent;
use App\Domain\Request\Action;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The expected outcomes come from the command table of architecture section 6 and the command and allowedActions
 * tables of docs/specs/rest-api-requests.md section 1. The review is open, the input is valid, and every link is
 * available, so only the state machine decides.
 */
final class TransitionMatrixTest extends KernelTestCase
{
    private const string IT = 'invalid_transition';
    private const string NO_EVENT = 'no event';

    /**
     * The claiming agent is {@see LifecycleFixtures::agent()}, which holds the claim of the in_progress state.
     * Leaving in_progress clears claimedBy, so the other states name no holder: there the claiming agent is the agent
     * that claims next or claimed before, and the docs give it the same rights as another agent.
     */
    private const array CALLERS = ['user', 'claiming agent', 'another agent'];

    private const array STATES = [
        'open',
        'in_progress',
        'ready_for_review',
        'verified',
        'reopened',
        'wont_do set by an agent',
        'wont_do set by the user',
    ];

    /**
     * Command, then the state before it, then the outcome for each of {@see self::CALLERS}.
     */
    private const array OUTCOMES = [
        'open' => [
            'none' => ['request.opened', 'forbidden', 'forbidden'],
        ],
        'claim' => [
            'open' => [self::IT, 'request.claimed', 'request.claimed'],
            'in_progress' => [self::IT, self::NO_EVENT, self::IT],
            'ready_for_review' => [self::IT, self::IT, self::IT],
            'verified' => [self::IT, self::IT, self::IT],
            'reopened' => [self::IT, 'request.claimed', 'request.claimed'],
            'wont_do set by an agent' => [self::IT, self::IT, self::IT],
            'wont_do set by the user' => [self::IT, self::IT, self::IT],
        ],
        'release' => [
            'open' => [self::IT, self::IT, self::IT],
            'in_progress' => ['request.released', 'request.released', self::IT],
            'ready_for_review' => [self::IT, self::IT, self::IT],
            'verified' => [self::IT, self::IT, self::IT],
            'reopened' => [self::IT, self::IT, self::IT],
            'wont_do set by an agent' => [self::IT, self::IT, self::IT],
            'wont_do set by the user' => [self::IT, self::IT, self::IT],
        ],
        'link_commits' => [
            'open' => [self::IT, self::IT, self::IT],
            'in_progress' => [self::IT, 'commit.linked', self::IT],
            'ready_for_review' => [self::IT, self::IT, self::IT],
            'verified' => [self::IT, self::IT, self::IT],
            'reopened' => [self::IT, self::IT, self::IT],
            'wont_do set by an agent' => [self::IT, self::IT, self::IT],
            'wont_do set by the user' => [self::IT, self::IT, self::IT],
        ],
        'unlink_commit' => [
            'open' => [self::IT, self::IT, self::IT],
            'in_progress' => [self::IT, 'commit.unlinked', self::IT],
            'ready_for_review' => [self::IT, self::IT, self::IT],
            'verified' => [self::IT, self::IT, self::IT],
            'reopened' => [self::IT, self::IT, self::IT],
            'wont_do set by an agent' => [self::IT, self::IT, self::IT],
            'wont_do set by the user' => [self::IT, self::IT, self::IT],
        ],
        'mark_ready_for_review' => [
            'open' => [self::IT, self::IT, self::IT],
            'in_progress' => [self::IT, 'request.ready_for_review', self::IT],
            'ready_for_review' => [self::IT, self::IT, self::IT],
            'verified' => [self::IT, self::IT, self::IT],
            'reopened' => [self::IT, self::IT, self::IT],
            'wont_do set by an agent' => [self::IT, self::IT, self::IT],
            'wont_do set by the user' => [self::IT, self::IT, self::IT],
        ],
        'mark_wont_do' => [
            'open' => ['request.wont_do', 'request.wont_do', 'request.wont_do'],
            'in_progress' => ['request.wont_do', 'request.wont_do', self::IT],
            'ready_for_review' => [self::IT, self::IT, self::IT],
            'verified' => [self::IT, self::IT, self::IT],
            'reopened' => ['request.wont_do', 'request.wont_do', 'request.wont_do'],
            'wont_do set by an agent' => [self::IT, self::IT, self::IT],
            'wont_do set by the user' => [self::IT, self::IT, self::IT],
        ],
        'verify' => [
            'open' => [self::IT, self::IT, self::IT],
            'in_progress' => [self::IT, self::IT, self::IT],
            'ready_for_review' => ['request.verified', self::IT, self::IT],
            'verified' => [self::IT, self::IT, self::IT],
            'reopened' => [self::IT, self::IT, self::IT],
            'wont_do set by an agent' => ['request.verified', self::IT, self::IT],
            'wont_do set by the user' => [self::IT, self::IT, self::IT],
        ],
        'reopen' => [
            'open' => [self::IT, self::IT, self::IT],
            'in_progress' => [self::IT, self::IT, self::IT],
            'ready_for_review' => ['request.reopened', self::IT, self::IT],
            'verified' => ['request.reopened', self::IT, self::IT],
            'reopened' => [self::IT, self::IT, self::IT],
            'wont_do set by an agent' => ['request.reopened', self::IT, self::IT],
            'wont_do set by the user' => ['request.reopened', self::IT, self::IT],
        ],
        'reply' => [
            'open' => ['request.commented', 'request.commented', 'request.commented'],
            'in_progress' => ['request.commented', 'request.commented', 'request.commented'],
            'ready_for_review' => ['request.commented', 'request.commented', 'request.commented'],
            'verified' => ['request.commented', 'request.commented', 'request.commented'],
            'reopened' => ['request.commented', 'request.commented', 'request.commented'],
            'wont_do set by an agent' => ['request.commented', 'request.commented', 'request.commented'],
            'wont_do set by the user' => ['request.commented', 'request.commented', 'request.commented'],
        ],
    ];

    #[DataProvider('cases')]
    #[TestDox('Command $command by $caller on $state gives $outcome')]
    public function testCommand(string $command, string $caller, string $state, string $outcome): void
    {
        $actor = self::actor($caller);
        $requestState = 'none' === $state ? null : self::state($state);
        $action = 'open' === $command ? null : Action::from($command);
        $result = self::decide($action, $requestState, $actor);

        $errorCode = ErrorCode::tryFrom($outcome);
        if (null !== $errorCode) {
            self::assertInstanceOf(DomainError::class, $result, 'Gives '.self::describe($result));
            self::assertSame($errorCode, $result->code(), $result->getMessage());
            self::assertEquals(self::expectedDetails($errorCode, $action, $requestState, $actor), $result->details());

            return;
        }

        self::assertIsArray($result, 'Gives '.self::describe($result));
        $expectedEvents = match (true) {
            self::NO_EVENT === $outcome => [],
            null === $action => ValidCommands::expectedOpenEvents(),
            default => ValidCommands::expectedEvents($action, self::requireState($requestState)->requestId),
        };
        self::assertSame($outcome, self::describe($expectedEvents), 'The outcome table names another event than the command gives.');
        self::assertEquals($expectedEvents, $result);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function cases(): iterable
    {
        self::requireCompleteMatrix();
        foreach (self::OUTCOMES as $command => $outcomesByState) {
            foreach ($outcomesByState as $state => $outcomes) {
                foreach (self::CALLERS as $index => $caller) {
                    yield \sprintf('%s by %s on %s', $command, $caller, $state) => [$command, $caller, $state, $outcomes[$index]];
                }
            }
        }
    }

    private static function requireCompleteMatrix(): void
    {
        $expectedCommands = ['open', ...array_map(static fn (Action $action): string => $action->value, Action::cases())];
        $commands = array_keys(self::OUTCOMES);
        sort($expectedCommands);
        sort($commands);
        if ($expectedCommands !== $commands) {
            throw new \LogicException('The matrix must hold the 10 request commands.');
        }
        foreach (self::OUTCOMES as $command => $outcomesByState) {
            if (array_keys($outcomesByState) !== ('open' === $command ? ['none'] : self::STATES)) {
                throw new \LogicException(\sprintf('The matrix must hold every state before %s.', $command));
            }
        }
    }

    /**
     * @return DomainError|list<DecidedEvent>
     */
    private static function decide(?Action $action, ?RequestState $state, Actor $actor): DomainError|array
    {
        $command = null === $action ? ValidCommands::open() : ValidCommands::forAction($action, self::requireState($state)->requestId);
        try {
            return RequestDecider::decide($command, null === $state ? [] : [$state], LifecycleFixtures::openReview(), $actor, ValidCommands::availability());
        } catch (DomainError $error) {
            return $error;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function expectedDetails(ErrorCode $errorCode, ?Action $action, ?RequestState $state, Actor $actor): ?array
    {
        if (ErrorCode::InvalidTransition !== $errorCode) {
            return null;
        }
        $state = self::requireState($state);

        return [
            'requestId' => $state->requestId,
            'action' => $action,
            'status' => $state->status,
            'claimedBy' => $state->claimedBy,
            'allowedActions' => SpecAllowedActions::for($state, $actor),
        ];
    }

    /**
     * @param DomainError|list<DecidedEvent> $result
     */
    private static function describe(DomainError|array $result): string
    {
        if ($result instanceof DomainError) {
            return $result->code()->value;
        }

        return [] === $result ? self::NO_EVENT : implode(', ', array_map(static fn (DecidedEvent $event): string => $event->type()->value, $result));
    }

    private static function actor(string $caller): Actor
    {
        return match ($caller) {
            'user' => LifecycleFixtures::user(),
            'claiming agent' => LifecycleFixtures::agent(),
            'another agent' => LifecycleFixtures::otherAgent(),
            default => throw new \LogicException('Unknown caller '.$caller),
        };
    }

    private static function state(string $state): RequestState
    {
        $links = [ValidCommands::linkedCommit()];

        return match ($state) {
            'open' => LifecycleFixtures::state(RequestStatus::Open, links: $links),
            'in_progress' => LifecycleFixtures::state(RequestStatus::InProgress, claimedBy: LifecycleFixtures::agent(), links: $links),
            'ready_for_review' => LifecycleFixtures::state(RequestStatus::ReadyForReview, links: $links),
            'verified' => LifecycleFixtures::state(RequestStatus::Verified, links: $links),
            'reopened' => LifecycleFixtures::state(RequestStatus::Reopened, links: $links),
            'wont_do set by an agent' => LifecycleFixtures::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::Agent, links: $links),
            'wont_do set by the user' => LifecycleFixtures::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::User, links: $links),
            default => throw new \LogicException('Unknown state '.$state),
        };
    }

    private static function requireState(?RequestState $state): RequestState
    {
        return $state ?? throw new \LogicException('Only open runs without a state.');
    }
}
