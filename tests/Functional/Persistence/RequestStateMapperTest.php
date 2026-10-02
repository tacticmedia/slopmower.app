<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Git\CommitSha;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\Reply;
use App\Entity\ChangeRequest;
use App\Projection\RequestStateMapper;
use App\Repository\CommitLinkRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class RequestStateMapperTest extends KernelTestCase
{
    use ResetDatabase;
    use ReviewDataFixtures;

    #[TestDox('States from the M04 scenarios survive the round trip')]
    #[DataProvider('scenarios')]
    public function testStatesSurviveTheRoundTrip(\Closure $scenario): void
    {
        $tenantId = self::enterNewTenant();
        /** @var RequestScenario $built */
        $built = $scenario();
        $state = $built->state();
        $requestId = self::persistScenario($tenantId, $built);

        $request = self::entityManager()->find(ChangeRequest::class, Ulid::fromString($requestId->toString())) ?? self::fail('The request did not reload.');
        $links = self::getContainer()->get(CommitLinkRepository::class)->findBy(['request' => $request]);

        self::assertEquals($state, RequestStateMapper::toState($request, $links));
        self::assertSame(AwaitingPolicy::evaluate($state), $request->getAwaiting());
    }

    /**
     * @return iterable<string, array{\Closure(): RequestScenario}>
     */
    public static function scenarios(): iterable
    {
        foreach (RequestScenario::STATES as $state) {
            yield $state => [static fn (): RequestScenario => RequestScenario::inState($state)];
        }

        yield 'in_progress with a pending link, after an agent question and a user answer' => [static function (): RequestScenario {
            $scenario = RequestScenario::inState('in_progress');
            $agent = RequestScenario::claimingAgent();
            $scenario->run(new LinkCommits(CommitSha::fromString(RequestScenario::OTHER_COMMIT), [$scenario->requestId()]), $agent);
            $scenario->run(new Reply($scenario->requestId(), 'Also the shipping totals?', question: true), $agent);
            $scenario->run(new Reply($scenario->requestId(), 'Yes, both.'), RequestScenario::user());

            return $scenario;
        }];
    }
}
