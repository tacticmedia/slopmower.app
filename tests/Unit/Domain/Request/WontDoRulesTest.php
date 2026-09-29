<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidTransition;
use App\Domain\Event\RequestVerified;
use App\Domain\Event\RequestWontDo;
use App\Domain\Request\Action;
use App\Domain\Request\Awaiting;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Resolution;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WontDoRulesTest extends KernelTestCase
{
    use CatchesDomainErrors;
    use ViolatedFields;

    private const string REASON = 'The float is rounded once at the end, so the totals are exact.';

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortReasons(): iterable
    {
        yield '19 characters' => [str_repeat('a', 19)];
        yield '19 characters inside whitespace' => ["  \t".str_repeat('a', 19)."\n\u{00A0}"];
        yield '19 two-byte characters' => [str_repeat("\u{00E9}", 19)];
    }

    #[DataProvider('shortReasons')]
    #[TestDox('Rejects a reason with fewer than 20 characters')]
    public function testRejectsAShortReason(string $reason): void
    {
        self::assertSame(['reason'], self::violatedFields(static fn (): MarkWontDo => new MarkWontDo(LifecycleFixtures::requestId(), $reason)));
    }

    #[TestDox("An agent's wont_do waits for the user")]
    public function testAnAgentsWontDoWaitsForTheUser(): void
    {
        $wontDo = self::markWontDo(LifecycleFixtures::agent());

        self::assertSame(
            [RequestStatus::WontDo, ActorKind::Agent, Awaiting::User, false, Resolution::WontDo],
            [$wontDo->status, $wontDo->wontDoSetBy, AwaitingPolicy::of($wontDo), $wontDo->isClosed(), $wontDo->resolution()],
        );
    }

    #[TestDox("A user's wont_do closes the request")]
    public function testAUsersWontDoClosesTheRequest(): void
    {
        $wontDo = self::markWontDo(LifecycleFixtures::user());

        self::assertSame(
            [RequestStatus::WontDo, ActorKind::User, Awaiting::None, true, Resolution::WontDo],
            [$wontDo->status, $wontDo->wontDoSetBy, AwaitingPolicy::of($wontDo), $wontDo->isClosed(), $wontDo->resolution()],
        );
    }

    #[TestDox('The user verifies a wont_do only when an agent set it')]
    public function testTheUserVerifiesAnAgentsWontDoOnly(): void
    {
        $user = LifecycleFixtures::user();
        $verify = new Verify(LifecycleFixtures::requestId(), 'Agreed.');
        $setByAgent = LifecycleFixtures::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::Agent);
        $setByUser = LifecycleFixtures::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::User);

        $decided = RequestDecider::decide($verify, [$setByAgent], LifecycleFixtures::openReview(), $user);
        $verified = LifecycleFixtures::applyDecided($setByAgent, $decided, $user);
        $error = self::domainError(InvalidTransition::class, static fn (): array => RequestDecider::decide($verify, [$setByUser], LifecycleFixtures::openReview(), $user));

        self::assertEquals([new RequestVerified('Agreed.')], array_column($decided, 'event'));
        self::assertSame([RequestStatus::Verified, Resolution::WontDo, true], [$verified->status, $verified->resolution(), $verified->isClosed()]);
        self::assertSame([Action::Verify, [Action::Reply, Action::Reopen]], [$error->details()['action'], $error->details()['allowedActions']]);
    }

    private static function markWontDo(Actor $actor): RequestState
    {
        $open = LifecycleFixtures::state(RequestStatus::Open);
        $decided = RequestDecider::decide(new MarkWontDo(LifecycleFixtures::requestId(), self::REASON), [$open], LifecycleFixtures::openReview(), $actor);
        self::assertEquals([new RequestWontDo(self::REASON)], array_column($decided, 'event'));

        return LifecycleFixtures::applyDecided($open, $decided, $actor);
    }
}
