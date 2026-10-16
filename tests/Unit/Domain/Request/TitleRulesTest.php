<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\Command\SetTitle;
use App\Domain\Request\Command\Suggest;
use App\Domain\Request\InvalidTransition;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/specs/README.md section 2.2 and docs/specs/rest-api-requests.md section 1, "Titles".
 */
final class TitleRulesTest extends KernelTestCase
{
    use InvalidInputAssertions;

    private const string TITLE = 'Use Money for totals';

    #[TestDox('A title $case gives invalid_input')]
    #[DataProvider('invalidTitles')]
    public function testRefusesAnInvalidTitle(string $case, string $title): void
    {
        $scenario = RequestScenario::inState('open');

        self::assertInvalidInput(['title'], static fn () => $scenario->decide(new SetTitle($scenario->requestId(), $title), RequestScenario::user()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTitles(): iterable
    {
        yield 'too long' => ['of 201 characters', str_repeat('a', 201)];
        yield 'two lines' => ['with a line break', "Use Money\nfor totals"];
        yield 'blank' => ['of whitespace only', " \t "];
    }

    #[TestDox('A suggestion without a title gives invalid_input')]
    public function testSuggestionNeedsATitle(): void
    {
        $scenario = new RequestScenario();

        self::assertInvalidInput(['title'], static fn () => $scenario->decide(new Suggest($scenario->anchor(), 'Round each line before the sum.'), RequestScenario::claimingAgent()));
    }

    #[TestDox('An agent that sets the title of a titled request gets invalid_transition, also with the same title')]
    public function testAgentSetsOnlyAMissingTitle(): void
    {
        $scenario = RequestScenario::inState('open');
        $agent = RequestScenario::claimingAgent();
        $scenario->run(new SetTitle($scenario->requestId(), self::TITLE), $agent);

        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage('The request is open, so set_title is not allowed: the request has a title, and only the user changes it.');

        $scenario->decide(new SetTitle($scenario->requestId(), self::TITLE), $agent);
    }

    #[TestDox('The user\'s title is stored trimmed, and the same title again writes no event')]
    public function testSameTitleWritesNoEvent(): void
    {
        $scenario = RequestScenario::inState('open');
        $scenario->run(new SetTitle($scenario->requestId(), ' '.self::TITLE.' '), RequestScenario::user());

        self::assertSame(self::TITLE, $scenario->state()->title);
        self::assertSame([], $scenario->decide(new SetTitle($scenario->requestId(), self::TITLE), RequestScenario::user()));
    }
}
