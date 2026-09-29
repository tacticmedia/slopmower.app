<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Actor\Actor;
use App\Domain\Event\RequestClaimed;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\TenantId;
use App\Domain\Request\CommitLinkState;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use App\Entity\Repository;
use App\Entity\ReviewEvent;
use App\Entity\User;
use App\Repository\CommitLinkRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\ReviewData;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UniqueConstraintsTest extends KernelTestCase
{
    use ResetDatabase;

    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $this->alice = $fixtures->localUser('alice');
        $this->bob = $fixtures->localUser('bob');
        self::getContainer()->get(TenantContext::class)->set($this->alice->getTenantId());
    }

    #[TestDox('A duplicate commit link fails')]
    public function testADuplicateCommitLinkFails(): void
    {
        $agent = Actor::agent($this->alice->getId(), 'claude-code laptop');
        $review = ReviewData::review(ReviewData::gitHubRepository($this->alice->getTenantId()), Actor::user($this->alice->getId()));
        $request = ReviewData::request($review, Actor::user($this->alice->getId()));
        $otherRequest = ReviewData::request($review, Actor::user($this->alice->getId()));
        $fixCommit = CommitSha::fromString(ReviewData::FIX_COMMIT);
        self::store(
            $review->getRepository(),
            $review,
            $request,
            $otherRequest,
            CommitLink::pending($request, $fixCommit, $agent, ReviewData::time(), 3, null),
            CommitLink::pending($request, CommitSha::fromString(ReviewData::OTHER_COMMIT), $agent, ReviewData::time(), 3, null),
            CommitLink::pending($otherRequest, $fixCommit, $agent, ReviewData::time(), 3, null),
        );
        $storedRequest = self::entityManager()->find(ChangeRequest::class, $request->getId()->toUlid());
        self::assertInstanceOf(ChangeRequest::class, $storedRequest);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_commit_link_request_commit');

        self::store(CommitLink::pending($storedRequest, $fixCommit, $agent, ReviewData::time(), 4, 'Again.'));
    }

    #[TestDox('A commit that is unlinked and linked again in one flush reuses its row and reloads with the new values')]
    public function testARelinkAfterAnUnlinkInOneFlushReusesTheRow(): void
    {
        $user = Actor::user($this->alice->getId());
        $review = ReviewData::review(ReviewData::gitHubRepository($this->alice->getTenantId()), $user);
        $request = ReviewData::request($review, $user);
        $fixCommit = CommitSha::fromString(ReviewData::FIX_COMMIT);
        $agent = Actor::agent($this->alice->getId(), 'claude-code laptop');
        self::store($review->getRepository(), $review, $request, CommitLink::available($request, $fixCommit, $agent, ReviewData::time('2026-09-29T07:45:00Z'), 3, 'First.', 'Use Money', ReviewData::time('2026-09-29T07:44:10Z')));
        $link = self::commitLink($request, $fixCommit);
        $entityManager = self::entityManager();

        $entityManager->remove($link);
        $entityManager->persist($link);
        $link->relink($user, ReviewData::time('2026-09-29T08:10:00Z'), 7, 'Again.');
        $expected = EntitySnapshot::of($link);
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = self::commitLink($request, $fixCommit);
        self::assertSame($expected, EntitySnapshot::of($reloaded));
        self::assertSame([CommitLinkState::Pending, null, null, null], [$reloaded->getState(), $reloaded->getFoundAt(), $reloaded->getSubject(), $reloaded->getCommittedAt()]);
    }

    #[TestDox('A duplicate tenant and seq pair fails')]
    public function testADuplicateTenantAndSeqPairFails(): void
    {
        self::store($this->claimedEvent($this->alice, 1), $this->claimedEvent($this->alice, 2));
        self::getContainer()->get(TenantFilter::class)->suspend(function (): void {
            self::store($this->claimedEvent($this->bob, 1));
        });

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_review_event_tenant_seq');

        self::store($this->claimedEvent($this->alice, 1));
    }

    #[TestDox('A duplicate repository name in one tenant fails')]
    public function testADuplicateRepositoryNameInOneTenantFails(): void
    {
        self::store(ReviewData::gitHubRepository($this->alice->getTenantId(), githubRepositoryId: 1));
        $this->storeForBob(static fn (TenantId $tenantId): Repository => ReviewData::gitHubRepository($tenantId, githubRepositoryId: 1));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_repository_tenant_source_name');

        self::store(ReviewData::gitHubRepository($this->alice->getTenantId(), githubRepositoryId: 2));
    }

    #[TestDox('A duplicate GitHub repository ID in one tenant fails')]
    public function testADuplicateGitHubRepositoryIdInOneTenantFails(): void
    {
        self::store(
            ReviewData::gitHubRepository($this->alice->getTenantId(), 'acme/billing', 1),
            ReviewData::localRepository($this->alice->getTenantId(), 'billing'),
            ReviewData::localRepository($this->alice->getTenantId(), 'shipping'),
        );
        $this->storeForBob(static fn (TenantId $tenantId): Repository => ReviewData::gitHubRepository($tenantId, 'acme/billing', 1));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_repository_tenant_github_repository_id');

        self::store(ReviewData::gitHubRepository($this->alice->getTenantId(), 'acme/billing-renamed', 1));
    }

    private function claimedEvent(User $user, int $seq): ReviewEvent
    {
        $review = ReviewData::review(ReviewData::gitHubRepository($user->getTenantId()), Actor::user($user->getId()));

        return ReviewEvent::record(ReviewData::recordedEvent($user->getTenantId(), $review->getId(), $seq, new RequestClaimed(), Actor::agent($user->getId(), 'claude-code laptop')));
    }

    /**
     * @param \Closure(TenantId): Repository $createRepository
     */
    private function storeForBob(\Closure $createRepository): void
    {
        self::getContainer()->get(TenantFilter::class)->suspend(function () use ($createRepository): void {
            self::store($createRepository($this->bob->getTenantId()));
        });
    }

    private static function commitLink(ChangeRequest $request, CommitSha $commitSha): CommitLink
    {
        $link = self::getContainer()->get(CommitLinkRepository::class)->findOneBy(['request' => $request->getId()->toUlid(), 'commitSha' => $commitSha]);
        self::assertInstanceOf(CommitLink::class, $link);

        return $link;
    }

    private static function store(object ...$entities): void
    {
        $entityManager = self::entityManager();
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
