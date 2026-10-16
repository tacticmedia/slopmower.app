<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\Awaiting;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\Command\Reply;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AwaitingPolicyTest extends KernelTestCase
{
    /**
     * @param \Closure(): RequestScenario $scenario
     */
    #[TestDox('Rule $rule gives $awaiting')]
    #[DataProvider('rules')]
    public function testRule(int $rule, string $awaiting, \Closure $scenario): void
    {
        self::assertSame(Awaiting::from($awaiting), AwaitingPolicy::evaluate($scenario()->state()));
    }

    /**
     * @return iterable<string, array{int, string, \Closure(): RequestScenario}>
     */
    public static function rules(): iterable
    {
        yield 'verified' => [1, 'none', static fn (): RequestScenario => RequestScenario::inState('verified')];
        yield 'user reply after ready_for_review' => [2, 'agent', static fn (): RequestScenario => self::userReply(RequestScenario::inState('ready_for_review'))];
        yield 'agent question while in_progress' => [3, 'user', static fn (): RequestScenario => self::agentQuestion(RequestScenario::inState('in_progress'))];
        yield 'pending proposal' => [3, 'user', static fn (): RequestScenario => RequestScenario::inState('proposal pending')];
        yield 'proposal decision' => [2, 'agent', static fn (): RequestScenario => self::decision(RequestScenario::inState('proposal pending'), 'approve_proposal')];
        yield 'pending suggestion' => [3, 'user', static fn (): RequestScenario => RequestScenario::inState('suggestion pending')];
        yield 'open' => [4, 'agent', static fn (): RequestScenario => RequestScenario::inState('open')];
        yield 'ready_for_review' => [5, 'user', static fn (): RequestScenario => RequestScenario::inState('ready_for_review')];
    }

    /**
     * @param \Closure(): RequestScenario $scenario
     */
    #[TestDox('An earlier rule wins over a later one')]
    #[DataProvider('overlaps')]
    public function testEarlierRuleWins(string $awaiting, \Closure $scenario): void
    {
        self::assertSame(Awaiting::from($awaiting), AwaitingPolicy::evaluate($scenario()->state()));
    }

    /**
     * @return iterable<string, array{string, \Closure(): RequestScenario}>
     */
    public static function overlaps(): iterable
    {
        yield 'rule 1 over rule 2: user reply on verified' => ['none', static fn (): RequestScenario => self::userReply(RequestScenario::inState('verified'))];
        yield 'rule 2 over rule 3: user reply to a question' => ['agent', static fn (): RequestScenario => self::userReply(self::agentQuestion(RequestScenario::inState('in_progress')))];
        yield 'rule 2 over rule 3: user reply to a pending proposal' => ['agent', static fn (): RequestScenario => self::userReply(RequestScenario::inState('proposal pending'))];
        yield 'rule 2 over rule 3: proposal decision after an agent question' => ['agent', static fn (): RequestScenario => self::decision(self::agentQuestion(RequestScenario::inState('proposal pending')), 'reject_proposal')];
        yield 'rule 2 over rule 3: user reply to a pending suggestion' => ['agent', static fn (): RequestScenario => self::userReply(RequestScenario::inState('suggestion pending'))];
        yield 'rule 2 over rule 3: suggestion accept after an agent question' => ['agent', static fn (): RequestScenario => self::decision(self::agentQuestion(RequestScenario::inState('suggestion pending')), 'accept_suggestion')];
        yield 'rule 3 over rule 4: agent question on reopened' => ['user', static fn (): RequestScenario => self::agentQuestion(RequestScenario::inState('reopened'))];
        yield 'rule 2 over rule 5: user reply on an agent\'s wont_do' => ['agent', static fn (): RequestScenario => self::userReply(RequestScenario::inState('wont_do by agent'))];
    }

    #[TestDox('A pull request link after a user reply on a ready_for_review request keeps awaiting agent')]
    public function testPullRequestLinkKeepsAwaiting(): void
    {
        $scenario = self::userReply(RequestScenario::inState('ready_for_review'));

        $scenario->run($scenario->validCommand('link_pull_request'), RequestScenario::claimingAgent());

        self::assertSame(Awaiting::Agent, AwaitingPolicy::evaluate($scenario->state()));
    }

    /**
     * @param \Closure(): RequestScenario $scenario
     */
    #[TestDox('A retitle by the $caller keeps awaiting $awaiting')]
    #[DataProvider('retitles')]
    public function testRetitleKeepsAwaiting(string $caller, string $awaiting, \Closure $scenario): void
    {
        $scenario = $scenario();

        $scenario->run($scenario->validCommand('set_title'), RequestScenario::caller($caller));

        self::assertSame(Awaiting::from($awaiting), AwaitingPolicy::evaluate($scenario->state()));
    }

    /**
     * @return iterable<string, array{string, string, \Closure(): RequestScenario}>
     */
    public static function retitles(): iterable
    {
        yield 'agent after a user reply' => ['claiming agent', 'agent', static fn (): RequestScenario => self::userReply(RequestScenario::inState('ready_for_review'))];
        yield 'user after an agent question' => ['user', 'user', static fn (): RequestScenario => self::agentQuestion(RequestScenario::inState('in_progress'))];
    }

    private static function userReply(RequestScenario $scenario): RequestScenario
    {
        $scenario->run(new Reply($scenario->requestId(), 'Please also cover the shipping totals.'), RequestScenario::user());

        return $scenario;
    }

    private static function decision(RequestScenario $scenario, string $command): RequestScenario
    {
        $scenario->run($scenario->validCommand($command), RequestScenario::user());

        return $scenario;
    }

    private static function agentQuestion(RequestScenario $scenario): RequestScenario
    {
        $scenario->run(new Reply($scenario->requestId(), 'Should TaxRounding also cover shipping?', question: true), RequestScenario::claimingAgent());

        return $scenario;
    }
}
