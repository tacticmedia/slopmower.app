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
        yield 'rule 3 over rule 4: agent question on reopened' => ['user', static fn (): RequestScenario => self::agentQuestion(RequestScenario::inState('reopened'))];
        yield 'rule 2 over rule 5: user reply on an agent\'s wont_do' => ['agent', static fn (): RequestScenario => self::userReply(RequestScenario::inState('wont_do by agent'))];
    }

    private static function userReply(RequestScenario $scenario): RequestScenario
    {
        $scenario->run(new Reply($scenario->requestId(), 'Please also cover the shipping totals.'), RequestScenario::user());

        return $scenario;
    }

    private static function agentQuestion(RequestScenario $scenario): RequestScenario
    {
        $scenario->run(new Reply($scenario->requestId(), 'Should TaxRounding also cover shipping?', question: true), RequestScenario::claimingAgent());

        return $scenario;
    }
}
