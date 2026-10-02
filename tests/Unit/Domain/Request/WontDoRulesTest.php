<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\Awaiting;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\Resolution;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WontDoRulesTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Rejects a reason with fewer than 20 characters')]
    public function testRejectsShortReason(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $command = new MarkWontDo($scenario->requestId(), '  Not worth it.     ');

        self::assertInvalidInput(['reason'], static fn () => $scenario->decide($command, RequestScenario::claimingAgent()));
    }

    #[TestDox('An agent\'s wont_do waits for the user')]
    public function testAgentWontDoWaitsForTheUser(): void
    {
        $state = RequestScenario::inState('wont_do by agent')->state();

        self::assertSame(Awaiting::User, AwaitingPolicy::evaluate($state));
        self::assertFalse($state->closed());
        self::assertSame(Resolution::WontDo, $state->resolution);
    }

    #[TestDox('A user\'s wont_do closes the request')]
    public function testUserWontDoClosesTheRequest(): void
    {
        $state = RequestScenario::inState('wont_do by user')->state();

        self::assertSame(Awaiting::None, AwaitingPolicy::evaluate($state));
        self::assertTrue($state->closed());
        self::assertSame(Resolution::WontDo, $state->resolution);
    }

    #[TestDox('The user verifies a wont_do only when an agent set it')]
    public function testUserVerifiesOnlyAnAgentsWontDo(): void
    {
        $byAgent = RequestScenario::inState('wont_do by agent');
        $byUser = RequestScenario::inState('wont_do by user');

        self::assertSame('request.verified', $byAgent->outcome(new Verify($byAgent->requestId()), RequestScenario::user()));
        self::assertSame('invalid_transition', $byUser->outcome(new Verify($byUser->requestId()), RequestScenario::user()));
    }
}
