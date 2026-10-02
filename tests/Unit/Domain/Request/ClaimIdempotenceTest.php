<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidTransition;
use App\Domain\Identity\UserId;
use App\Domain\Request\Action;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\RequestStatus;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClaimIdempotenceTest extends KernelTestCase
{
    #[TestDox('A repeated claim by the same agent gives no event')]
    public function testRepeatedClaimGivesNoEvent(): void
    {
        $scenario = RequestScenario::inState('in_progress');

        self::assertSame([], $scenario->decide(new Claim($scenario->requestId()), RequestScenario::claimingAgent()));
    }

    #[TestDox('Tokens with the same agent name count as the same agent')]
    public function testTokensWithTheSameNameAreOneAgent(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $holder = RequestScenario::claimingAgent();
        $sameNameOtherUser = Actor::agent(UserId::fromString('01K6AZZ9Y8X7W6V5T4S3R2Q1P1'), (string) $holder->agentName);
        $claim = new Claim($scenario->requestId());

        self::assertSame([], $scenario->decide($claim, Actor::agent(UserId::fromString((string) $holder->userId), (string) $holder->agentName)));
        self::assertSame('invalid_transition', $scenario->outcome($claim, $sameNameOtherUser));
    }

    #[TestDox('A claim by another agent gives invalid_transition with claimedBy')]
    public function testClaimByAnotherAgentNamesTheHolder(): void
    {
        $scenario = RequestScenario::inState('in_progress');

        try {
            $scenario->decide(new Claim($scenario->requestId()), RequestScenario::otherAgent());
        } catch (InvalidTransition $error) {
            $details = $error->details();

            self::assertTrue($scenario->requestId()->equals($details['requestId']));
            self::assertSame(Action::Claim, $details['action']);
            self::assertSame(RequestStatus::InProgress, $details['status']);
            self::assertEquals(RequestScenario::claimingAgent(), $details['claimedBy']);
            self::assertSame([Action::Reply], $details['allowedActions']);

            return;
        }

        self::fail('No InvalidTransition was thrown.');
    }
}
