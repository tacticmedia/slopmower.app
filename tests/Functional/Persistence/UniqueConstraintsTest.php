<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class UniqueConstraintsTest extends KernelTestCase
{
    use ResetDatabase;
    use ReviewDataFixtures;

    #[TestDox('A duplicate commit link fails')]
    public function testDuplicateCommitLinkFails(): void
    {
        $tenantId = self::enterNewTenant();
        $requestId = self::persistScenario($tenantId, RequestScenario::inState('in_progress'));
        $request = self::entityManager()->find(ChangeRequest::class, Ulid::fromString($requestId->toString())) ?? self::fail('The request did not reload.');

        self::entityManager()->persist(new CommitLink($request, CommitSha::fromString(RequestScenario::LINKED_COMMIT), RequestScenario::otherAgent(), new \DateTimeImmutable(self::TIME), null));

        $this->expectException(UniqueConstraintViolationException::class);
        self::entityManager()->flush();
    }

    #[TestDox('A duplicate tenant and seq pair fails')]
    public function testDuplicateTenantAndSeqFails(): void
    {
        $tenantId = self::enterNewTenant();
        $event = static fn (): RecordedEvent => new RecordedEvent(EventId::generate(), $tenantId, 1, ReviewId::generate(), RequestId::generate(), RequestScenario::claimingAgent(), new \DateTimeImmutable(self::TIME), new RequestClaimedEvent());
        self::insertEvent($event());

        $this->expectException(UniqueConstraintViolationException::class);
        self::insertEvent($event());
    }
}
