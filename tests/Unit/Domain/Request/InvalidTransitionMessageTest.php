<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\InvalidTransition;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvalidTransitionMessageTest extends KernelTestCase
{
    #[TestDox('A closed proposal gate or a pending suggestion names its reason in the message, a status refusal does not')]
    #[DataProvider('refusals')]
    public function testMessageNamesTheClosedGate(string $state, string $action, string $message): void
    {
        $scenario = RequestScenario::inState($state);

        try {
            $scenario->decide($scenario->validCommand($action), RequestScenario::claimingAgent());
        } catch (InvalidTransition $error) {
            self::assertSame($message, $error->getMessage());

            return;
        }

        self::fail('No InvalidTransition was thrown.');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'pending proposal' => ['proposal pending', 'link_commits', "The request is in_progress, so link_commits is not allowed: the proposal waits for the user's decision."];
        yield 'rejected proposal' => ['proposal rejected', 'mark_ready_for_review', 'The request is in_progress, so mark_ready_for_review is not allowed: the user requested changes, so propose again.'];
        yield 'required plan without a proposal' => ['plan required', 'link_pull_request', 'The request is in_progress, so link_pull_request is not allowed: the request asks for a plan first, so propose a solution.'];
        yield 'pending suggestion' => ['suggestion pending', 'claim', 'The request is open, so claim is not allowed: the suggestion waits for the user to accept or dismiss it.'];
        yield 'status refusal' => ['open', 'link_commits', 'The request is open, so link_commits is not allowed.'];
    }
}
