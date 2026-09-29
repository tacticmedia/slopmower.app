<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Actor\Actor;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\RequestState;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use App\Projection\RequestStateMapper;
use App\Repository\CommitLinkRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\ReviewData;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Unit\Domain\Request\LifecycleFixtures;
use App\Tests\Unit\Domain\Request\ReducerTest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestStateMapperTest extends KernelTestCase
{
    use ResetDatabase;

    /**
     * The states that {@see ReducerTest} expects after each event type.
     *
     * @return iterable<string, array{RequestState}>
     */
    public static function m04States(): iterable
    {
        foreach (ReducerTest::eventsAndStates() as $scenario => [, , $state]) {
            yield 'after '.$scenario => [$state];
        }
    }

    #[DataProvider('m04States')]
    #[TestDox('States from the M04 scenarios survive the round trip')]
    public function testStatesFromTheM04ScenariosSurviveTheRoundTrip(RequestState $state): void
    {
        $user = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());
        $author = Actor::user($user->getId());
        $review = ReviewData::review(ReviewData::gitHubRepository($user->getTenantId()), $author, $state->reviewId);
        $request = ReviewData::request($review, $author, $state->requestId);
        RequestStateMapper::apply($state, $request, ReviewData::time('2026-09-29T08:00:00Z'));
        $commitLinks = self::commitLinksOf($state, $request);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([$review->getRepository(), $review, $request, ...$commitLinks] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = $entityManager->find(ChangeRequest::class, $state->requestId->toUlid());
        self::assertInstanceOf(ChangeRequest::class, $reloaded);
        $reloadedLinks = self::getContainer()->get(CommitLinkRepository::class)->findBy(['request' => $reloaded], ['linkedSeq' => 'DESC']);

        self::assertEquals($state, RequestStateMapper::toState($reloaded, $reloadedLinks));
        self::assertSame([AwaitingPolicy::of($state), $state->resolution()], [$reloaded->getAwaiting(), $reloaded->getResolution()]);
    }

    /**
     * @return list<CommitLink>
     */
    private static function commitLinksOf(RequestState $state, ChangeRequest $request): array
    {
        $agent = LifecycleFixtures::agent();
        $commitLinks = [];
        foreach ($state->links as $index => $link) {
            $linkedAt = ReviewData::time('2026-09-29T07:45:00Z')->modify(\sprintf('+%d minutes', $index));
            $commitLinks[] = CommitLinkState::Available === $link->state
                ? CommitLink::available($request, $link->commitSha, $agent, $linkedAt, $index + 2, null, 'Use Money', ReviewData::time('2026-09-29T07:44:10Z'))
                : CommitLink::pending($request, $link->commitSha, $agent, $linkedAt, $index + 2, null);
        }

        return $commitLinks;
    }
}
