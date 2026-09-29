<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositoryName;
use App\Domain\Repository\SyncState;
use App\Domain\Request\CommitLinkState;
use App\Domain\Review\ReviewState;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Repository;
use App\Tests\Support\Fixtures\ReviewData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntityRulesTest extends KernelTestCase
{
    #[TestDox('A review, its requests, comments, and commit links take the tenant of the repository')]
    public function testChildEntitiesTakeTheTenantOfTheRepository(): void
    {
        $tenantId = TenantId::generate();
        $review = ReviewData::review(ReviewData::gitHubRepository($tenantId), self::user());
        $request = ReviewData::request($review, self::user());
        $link = CommitLink::pending($request, CommitSha::fromString(ReviewData::FIX_COMMIT), self::user(), ReviewData::time(), 3, null);
        $comment = new Comment(CommentId::generate(), $request, self::user(), 'Noted.', false, null, ReviewData::time());

        foreach ([$review, $request, $comment, $link] as $entity) {
            self::assertTrue($tenantId->equals($entity->getTenantId()), $entity::class);
        }
    }

    /**
     * @return iterable<string, array{\Closure(): Repository}>
     */
    public static function repositoriesWithTheNameOfAnotherSource(): iterable
    {
        yield 'GitHub repository with a directory name' => [static fn (): Repository => Repository::gitHub(RepositoryId::generate(), TenantId::generate(), RepositoryName::local('billing'), 1, ReviewData::time())];
        yield 'local repository with an owner/name' => [static fn (): Repository => Repository::local(RepositoryId::generate(), TenantId::generate(), RepositoryName::gitHub('acme/billing'), 'billing', ReviewData::time())];
    }

    /**
     * @param \Closure(): Repository $create
     */
    #[DataProvider('repositoriesWithTheNameOfAnotherSource')]
    #[TestDox('A repository refuses the name of another source')]
    public function testARepositoryRefusesTheNameOfAnotherSource(\Closure $create): void
    {
        $this->expectException(\LogicException::class);

        $create();
    }

    #[TestDox('A removed repository is unavailable')]
    public function testARemovedRepositoryIsUnavailable(): void
    {
        $repository = ReviewData::localRepository(TenantId::generate());
        self::assertSame([RepositoryAccess::Active, SyncState::Cloning], [$repository->getAccess(), $repository->getSyncState()]);

        $repository->removeAccess();

        self::assertSame([RepositoryAccess::Removed, SyncState::Unavailable], [$repository->getAccess(), $repository->getSyncState()]);
    }

    #[TestDox('Closing a review sets the state, the time, the actor, and the note')]
    public function testClosingAReviewSetsTheCloseFields(): void
    {
        $review = ReviewData::review(ReviewData::gitHubRepository(TenantId::generate()), self::user());
        self::assertSame([ReviewState::Open, null, null, null], [$review->getState(), $review->getClosedAt(), $review->getClosedBy(), $review->getCloseNote()]);
        $closedAt = ReviewData::time('2026-09-30T16:30:00Z');

        $review->close($closedAt, self::user(), 'Shipped in 2.4');

        self::assertSame([ReviewState::Closed, $closedAt, 'Shipped in 2.4'], [$review->getState(), $review->getClosedAt(), $review->getCloseNote()]);
        self::assertTrue(self::user()->equals($review->getClosedBy() ?? Actor::system()));
    }

    #[TestDox('A link that is available at link time is found at the link time, and a pending link becomes available when found')]
    public function testCommitLinkAvailability(): void
    {
        $request = ReviewData::request(ReviewData::review(ReviewData::gitHubRepository(TenantId::generate()), self::user()), self::user());
        $linkedAt = ReviewData::time('2026-09-29T07:45:00Z');
        $committedAt = ReviewData::time('2026-09-29T07:44:10Z');
        $available = CommitLink::available($request, CommitSha::fromString(ReviewData::FIX_COMMIT), self::user(), $linkedAt, 3, null, 'Use Money', $committedAt);
        $pending = CommitLink::pending($request, CommitSha::fromString(ReviewData::OTHER_COMMIT), self::user(), $linkedAt, 4, null);
        self::assertSame([CommitLinkState::Available, $linkedAt, 'Use Money', $committedAt], [$available->getState(), $available->getFoundAt(), $available->getSubject(), $available->getCommittedAt()]);
        self::assertSame([CommitLinkState::Pending, null, null, null], [$pending->getState(), $pending->getFoundAt(), $pending->getSubject(), $pending->getCommittedAt()]);
        $foundAt = ReviewData::time('2026-09-29T07:52:30Z');

        $pending->markFound($foundAt, 'Extract TaxRounding', $committedAt);

        self::assertSame([CommitLinkState::Available, $foundAt, 'Extract TaxRounding', $committedAt], [$pending->getState(), $pending->getFoundAt(), $pending->getSubject(), $pending->getCommittedAt()]);
    }

    #[TestDox('An entity refuses a time with a fraction of a second')]
    public function testAnEntityRefusesATimeWithAFractionOfASecond(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('createdAt must have whole seconds');

        Repository::local(RepositoryId::generate(), TenantId::generate(), RepositoryName::local('billing'), 'billing', new \DateTimeImmutable('2026-09-29T07:30:00.5Z'));
    }

    #[TestDox('A repository keeps its default branch as a ref name, so a branch of more than 255 characters fails')]
    public function testARepositoryKeepsItsDefaultBranchAsARefName(): void
    {
        $repository = ReviewData::gitHubRepository(TenantId::generate());
        $repository->changeDefaultBranch(RefName::fromString('release/2.4'), null);
        self::assertTrue(RefName::fromString('release/2.4')->equals($repository->getDefaultBranch() ?? RefName::fromString('main')));

        $this->expectException(InvalidInput::class);

        $repository->changeDefaultBranch(RefName::fromString(str_repeat('b', 256), 'defaultBranch'), null);
    }

    #[TestDox('A local repository refuses a directory name of more than 255 characters')]
    public function testALocalRepositoryRefusesALongDirectoryName(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('localDirectory must have 1 to 255 characters');

        Repository::local(RepositoryId::generate(), TenantId::generate(), RepositoryName::local('billing'), str_repeat('b', 256), ReviewData::time());
    }

    private static function user(): Actor
    {
        return Actor::user(UserId::fromString('01K6AZZ9Y8X7W6V5T4S3R2Q1P0'));
    }
}
