<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\RequestCommented;
use App\Domain\Identity\CommentId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReplyRulesTest extends KernelTestCase
{
    use CatchesDomainErrors;

    private const string COMMENT_ID = '01K6C3C0MMENT0000000000002';

    #[TestDox('A user reply with question true gives invalid_input')]
    public function testAUserQuestionGivesInvalidInput(): void
    {
        $question = new Reply(LifecycleFixtures::requestId(), CommentId::fromString(self::COMMENT_ID), 'Why float?', question: true);

        $error = self::domainError(InvalidInput::class, static fn (): array => RequestDecider::decide(
            $question,
            [LifecycleFixtures::state(RequestStatus::InProgress)],
            LifecycleFixtures::closedReview(),
            LifecycleFixtures::user(),
        ));

        self::assertSame(['question'], array_column($error->details()['violations'], 'field'));
    }

    #[TestDox('An agent question hands the turn to the user, and the user reply hands it back')]
    public function testAnAgentQuestionAndTheUserReplySwapTheTurn(): void
    {
        $agent = LifecycleFixtures::agent();
        $user = LifecycleFixtures::user();
        $inProgress = LifecycleFixtures::state(RequestStatus::InProgress);
        $question = new Reply(LifecycleFixtures::requestId(), CommentId::fromString(self::COMMENT_ID), 'Keep the old method?', question: true);
        $answer = new Reply(LifecycleFixtures::requestId(), CommentId::generate(), 'Keep it as deprecated for one release.');

        $decidedQuestion = RequestDecider::decide($question, [$inProgress], LifecycleFixtures::openReview(), $agent);
        $asked = LifecycleFixtures::applyDecided($inProgress, $decidedQuestion, $agent);
        $answered = LifecycleFixtures::applyDecided($asked, RequestDecider::decide($answer, [$asked], LifecycleFixtures::openReview(), $user), $user);

        self::assertEquals([new RequestCommented(CommentId::fromString(self::COMMENT_ID), 'Keep the old method?', true, null)], array_column($decidedQuestion, 'event'));
        self::assertSame([Awaiting::User, Awaiting::Agent], [AwaitingPolicy::of($asked), AwaitingPolicy::of($answered)]);
    }

    /**
     * @return iterable<string, array{RequestStatus, list<RequestCommand>, RequestStatus}>
     */
    public static function userCommandsAfterAQuestion(): iterable
    {
        $requestId = LifecycleFixtures::requestId();
        $reopen = new Reopen($requestId, 'Yes, and round the tax per line.');

        yield 'reopen' => [RequestStatus::ReadyForReview, [$reopen], RequestStatus::Reopened];
        yield 'verify, then reopen' => [RequestStatus::ReadyForReview, [new Verify($requestId), $reopen], RequestStatus::Reopened];
        yield 'wont_do, then reopen' => [RequestStatus::Open, [new MarkWontDo($requestId, 'The old method has no callers left in the repository.'), $reopen], RequestStatus::Reopened];
        yield 'Unassign' => [RequestStatus::InProgress, [new Release($requestId, 'Unassign')], RequestStatus::Open];
    }

    /**
     * @param list<RequestCommand> $userCommands
     */
    #[DataProvider('userCommandsAfterAQuestion')]
    #[TestDox('A reopen or an Unassign after an agent question hands the turn to the agent')]
    public function testAReopenOrUnassignAfterAnAgentQuestionHandsTheTurnToTheAgent(RequestStatus $statusBeforeQuestion, array $userCommands, RequestStatus $expectedStatus): void
    {
        $agent = LifecycleFixtures::agent();
        $user = LifecycleFixtures::user();
        $state = LifecycleFixtures::state($statusBeforeQuestion);
        $question = new Reply(LifecycleFixtures::requestId(), CommentId::generate(), 'Update the changelog too?', question: true);

        $state = LifecycleFixtures::applyDecided($state, RequestDecider::decide($question, [$state], LifecycleFixtures::openReview(), $agent), $agent);
        foreach ($userCommands as $userCommand) {
            $state = LifecycleFixtures::applyDecided($state, RequestDecider::decide($userCommand, [$state], LifecycleFixtures::openReview(), $user), $user);
        }

        self::assertSame([$expectedStatus, Awaiting::Agent], [$state->status, AwaitingPolicy::of($state)]);
    }
}
