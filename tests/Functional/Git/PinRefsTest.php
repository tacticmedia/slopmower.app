<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;
use App\Git\GitCommandFailed;
use App\Git\PinRefConflict;
use App\Git\PinRefs;
use App\Tests\Support\Git\CloneLocation;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Git\ServerClones;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PinRefsTest extends KernelTestCase
{
    use ServerClones;

    #[TestDox('Creating a pin twice is idempotent')]
    public function testCreatingAPinTwiceIsIdempotent(): void
    {
        [$clone, $firstSha] = $this->fetchedClone();
        $reviewId = ReviewId::generate();

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            self::pinRefs()->pinReview($clone->tenantId, $clone->repositoryId, $reviewId, $firstSha);
            self::pinRefs()->pinCommit($clone->tenantId, $clone->repositoryId, $firstSha);
        }

        self::assertEquals(
            [PinRefs::commitRef($firstSha) => $firstSha, PinRefs::reviewRef($reviewId) => $firstSha],
            self::pinRefs()->list($clone->tenantId, $clone->repositoryId),
        );
    }

    #[TestDox('Moving a pin to another commit is refused')]
    public function testMovingAPinToAnotherCommitIsRefused(): void
    {
        [$clone, $firstSha, $secondSha] = $this->fetchedClone();
        $reviewId = ReviewId::generate();
        self::pinRefs()->pinReview($clone->tenantId, $clone->repositoryId, $reviewId, $firstSha);

        try {
            self::pinRefs()->pinReview($clone->tenantId, $clone->repositoryId, $reviewId, $secondSha);
            self::fail('The pin moved.');
        } catch (PinRefConflict $conflict) {
            self::assertSame(\sprintf('The pin %s points to %s and never moves to %s.', PinRefs::reviewRef($reviewId), $firstSha, $secondSha), $conflict->getMessage());
        }
        self::assertEquals([PinRefs::reviewRef($reviewId) => $firstSha], self::pinRefs()->list($clone->tenantId, $clone->repositoryId));
    }

    #[TestDox('Deleting a pin removes the ref')]
    public function testDeletingAPinRemovesTheRef(): void
    {
        [$clone, $firstSha, $secondSha] = $this->fetchedClone();
        $reviewId = ReviewId::generate();
        self::pinRefs()->pinReview($clone->tenantId, $clone->repositoryId, $reviewId, $firstSha);
        self::pinRefs()->pinCommit($clone->tenantId, $clone->repositoryId, $secondSha);

        self::pinRefs()->unpinReview($clone->tenantId, $clone->repositoryId, $reviewId);
        self::pinRefs()->unpinCommit($clone->tenantId, $clone->repositoryId, $secondSha);
        self::pinRefs()->unpinCommit($clone->tenantId, $clone->repositoryId, $secondSha);

        self::assertSame([], self::pinRefs()->list($clone->tenantId, $clone->repositoryId));
        self::assertSame(['refs/heads/main' => $secondSha->toString()], self::cloneRefs($clone->directory));
    }

    #[TestDox('Refuses a pin to a commit that the clone does not have')]
    public function testRefusesAPinToAMissingCommit(): void
    {
        [$clone] = $this->fetchedClone();
        $missingSha = CommitSha::fromString('0123456789abcdef0123456789abcdef01234567');

        try {
            self::pinRefs()->pinCommit($clone->tenantId, $clone->repositoryId, $missingSha);
            self::fail('The pin was written.');
        } catch (GitCommandFailed $failure) {
            self::assertSame(128, $failure->exitCode);
            self::assertStringContainsString('nonexistent object '.$missingSha, $failure->errorOutput);
        }
        self::assertSame([], self::pinRefs()->list($clone->tenantId, $clone->repositoryId));
    }

    /**
     * @return array{CloneLocation, CommitSha, CommitSha} the clone, the first and the second commit on main
     */
    private function fetchedClone(): array
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $firstSha = CommitSha::fromString($source->commit(['a.txt' => "a\n"]));
        $secondSha = CommitSha::fromString($source->commit(['a.txt' => "b\n"]));
        $clone = $this->initClone(self::remote($source));
        self::bareClone()->fetch($clone->tenantId, $clone->repositoryId, self::remote($source));

        return [$clone, $firstSha, $secondSha];
    }

    private static function pinRefs(): PinRefs
    {
        return self::getContainer()->get(PinRefs::class);
    }
}
