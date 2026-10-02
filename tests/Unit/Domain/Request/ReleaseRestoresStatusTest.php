<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\Release;
use App\Domain\Request\RequestStatus;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReleaseRestoresStatusTest extends KernelTestCase
{
    #[TestDox('Release restores $status')]
    #[DataProvider('statusesBeforeClaim')]
    public function testReleaseRestoresTheStatusBeforeTheClaim(string $status): void
    {
        $scenario = RequestScenario::inState($status);
        $agent = RequestScenario::claimingAgent();
        $scenario->run(new Claim($scenario->requestId()), $agent);

        $scenario->run(new Release($scenario->requestId(), 'Out of time.'), $agent);

        $state = $scenario->state();
        self::assertSame(RequestStatus::from($status), $state->status);
        self::assertNull($state->statusBeforeClaim);
        self::assertNull($state->claimedBy);
        self::assertNull($state->claimedAt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesBeforeClaim(): iterable
    {
        yield 'open' => ['open'];
        yield 'reopened' => ['reopened'];
    }

    #[TestDox('The user may release a claimed request')]
    public function testUserUnassignsTheAgent(): void
    {
        $scenario = RequestScenario::inState('in_progress');

        self::assertSame('request.released', $scenario->outcome(new Release($scenario->requestId()), RequestScenario::user()));
        $scenario->run(new Release($scenario->requestId()), RequestScenario::user());
        self::assertSame(RequestStatus::Open, $scenario->state()->status);
        self::assertNull($scenario->state()->claimedBy);
    }
}
