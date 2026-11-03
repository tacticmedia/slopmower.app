<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\CommentQuestion;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReplyRulesTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('A user reply with question true gives invalid_input')]
    public function testUserCannotAskAQuestion(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $command = new Reply($scenario->requestId(), 'Why?', question: true);

        self::assertInvalidInput(['question'], static fn () => $scenario->decide($command, RequestScenario::user()));
    }

    #[TestDox('An agent comment with questions is a question, with each question trimmed and unanswered')]
    public function testAgentQuestionsMakeAQuestion(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $command = new Reply($scenario->requestId(), 'Two questions.', questions: [new CommentQuestion(' Shipping too? ', 'Per line today.'), new CommentQuestion('Keep round()?')]);

        $event = $scenario->decide($command, RequestScenario::claimingAgent())[0]->event;

        self::assertInstanceOf(RequestCommentedEvent::class, $event);
        self::assertTrue($event->question);
        self::assertEquals([new CommentQuestion('Shipping too?', 'Per line today.'), new CommentQuestion('Keep round()?')], $event->questions);
    }

    #[TestDox('A user comment that answers every question may have an empty body and is no question')]
    public function testUserAnswersWithEmptyBody(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $command = new Reply($scenario->requestId(), '', questions: [new CommentQuestion('Shipping too?', answer: 'Yes'), new CommentQuestion('Keep round()?', answer: 'Only for one release.')]);

        $event = $scenario->decide($command, RequestScenario::user())[0]->event;

        self::assertInstanceOf(RequestCommentedEvent::class, $event);
        self::assertSame(['', false, ['Yes', 'Only for one release.']], [$event->body, $event->question, array_column(array_map(static fn (CommentQuestion $question): array => $question->toPayload(), $event->questions), 'answer')]);
    }

    /**
     * @param list<CommentQuestion> $questions
     */
    #[TestDox('$_dataName gives invalid_input')]
    #[DataProvider('invalidQuestions')]
    public function testInvalidQuestionsAreRejected(string $caller, array $questions, string $field): void
    {
        $scenario = RequestScenario::inState('in_progress');

        self::assertInvalidInput([$field], static fn () => $scenario->decide(new Reply($scenario->requestId(), 'Body.', questions: $questions), RequestScenario::caller($caller)));
    }

    /**
     * @return iterable<string, array{string, list<CommentQuestion>, string}>
     */
    public static function invalidQuestions(): iterable
    {
        yield 'An answer from an agent' => ['claiming agent', [new CommentQuestion('Shipping too?', answer: 'Yes')], 'questions.0.answer'];
        yield 'A question without an answer from a user' => ['user', [new CommentQuestion('Shipping too?', answer: 'Yes'), new CommentQuestion('Keep round()?')], 'questions.1.answer'];
        yield 'A question with a line break' => ['claiming agent', [new CommentQuestion("Shipping\ntoo?")], 'questions.0.question'];
        yield 'Eleven questions' => ['claiming agent', array_fill(0, 11, new CommentQuestion('Shipping too?')), 'questions'];
    }
}
