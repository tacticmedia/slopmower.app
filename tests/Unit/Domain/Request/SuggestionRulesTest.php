<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\ActorKind;
use App\Domain\Request\Command\AcceptSuggestion;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Resolution;
use App\Domain\Request\SuggestionState;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SuggestionRulesTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('A review takes 500 pending suggestions, and the 501st gives invalid_input')]
    public function testLimitsThePendingSuggestionsOfAReview(): void
    {
        $scenario = new RequestScenario();
        $suggest = static fn (int $pendingSuggestions): array => RequestDecider::decide($scenario->validCommand('suggest'), [], $scenario->review(), RequestScenario::claimingAgent(), pendingSuggestions: $pendingSuggestions);

        self::assertCount(1, $suggest(499));
        self::assertInvalidInput([''], static fn () => $suggest(500));
    }

    /**
     * @param \Closure(): RequestState $build
     */
    #[TestDox('A state with $case is inconsistent')]
    #[DataProvider('inconsistentStates')]
    public function testRejectsAnInconsistentState(string $case, \Closure $build): void
    {
        $this->expectException(\LogicException::class);

        $build();
    }

    /**
     * @return iterable<string, array{string, \Closure(): RequestState}>
     */
    public static function inconsistentStates(): iterable
    {
        yield 'user author' => ['a suggestion by a user', static fn (): RequestState => RequestScenario::inState('open')->state()->with(['suggestion' => SuggestionState::Accepted])];
        yield 'agent author' => ['no suggestion by an agent', static fn (): RequestState => RequestScenario::inState('suggestion pending')->state()->with(['suggestion' => null])];
        yield 'pending' => ['a pending suggestion that is not open', static fn (): RequestState => RequestScenario::inState('suggestion pending')->state()->with(['status' => RequestStatus::Reopened])];
        yield 'dismissed' => ['a dismissed suggestion that an agent set to wont_do', static function (): RequestState {
            $scenario = RequestScenario::inState('suggestion pending');
            $scenario->run(new AcceptSuggestion($scenario->requestId()), RequestScenario::user());

            return $scenario->state()->with([
                'status' => RequestStatus::WontDo,
                'wontDoBy' => ActorKind::Agent,
                'resolution' => Resolution::WontDo,
                'suggestion' => SuggestionState::Dismissed,
            ]);
        }];
    }
}
