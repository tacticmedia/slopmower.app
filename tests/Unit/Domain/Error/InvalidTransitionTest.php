<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidTransition;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\UserId;
use App\Domain\Request\Action;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvalidTransitionTest extends KernelTestCase
{
    private const string REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123456';

    #[TestDox('Returns the request, action, status, claim holder, and allowed actions as details')]
    public function testReturnsItsValuesAsDetails(): void
    {
        $requestId = RequestId::generate();
        $agent = Actor::agent(UserId::generate(), 'codex desktop');

        $error = new InvalidTransition($requestId, Action::Claim, RequestStatus::InProgress, $agent, [Action::Reply]);

        self::assertSame([
            'requestId' => $requestId,
            'action' => Action::Claim,
            'status' => RequestStatus::InProgress,
            'claimedBy' => $agent,
            'allowedActions' => [Action::Reply],
        ], $error->details());
    }

    #[TestDox('Lists each allowed action once in the order given')]
    public function testListsEachAllowedActionOnce(): void
    {
        $error = new InvalidTransition(RequestId::generate(), Action::Verify, RequestStatus::Open, null, [Action::Reply, Action::Claim, Action::Reply]);

        self::assertSame([Action::Reply, Action::Claim], $error->details()['allowedActions']);
    }

    #[TestDox('Names the agent that holds the claim in the message')]
    public function testNamesTheClaimHolderInTheMessage(): void
    {
        $agent = Actor::agent(UserId::generate(), 'codex desktop');

        $error = new InvalidTransition(RequestId::fromString(self::REQUEST_ID), Action::Claim, RequestStatus::InProgress, $agent, [Action::Reply]);

        self::assertSame(
            'Request 01K6B3P2A9QRSTVWXYZ0123456 in status in_progress does not allow claim. Agent codex desktop holds its claim.',
            $error->getMessage(),
        );
    }

    #[TestDox('Leaves the claim out of the message when no agent holds it')]
    public function testLeavesTheClaimOutOfTheMessageWithoutAHolder(): void
    {
        $error = new InvalidTransition(RequestId::fromString(self::REQUEST_ID), Action::Verify, RequestStatus::Open, null, [Action::Reply, Action::Claim]);

        self::assertSame('Request 01K6B3P2A9QRSTVWXYZ0123456 in status open does not allow verify.', $error->getMessage());
    }
}
