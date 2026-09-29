<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;
use App\Git\GitCommand;
use App\Git\PinRefs;
use App\Tests\Support\Git\CloneLocation;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Git\ServerClones;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The source deletes a branch and force-pushes another after the clone pinned their commits.
 */
final class PinRefsSurvivePruneTest extends KernelTestCase
{
    use ServerClones;

    private CloneLocation $clone;
    private ReviewId $reviewId;
    private CommitSha $reviewedSha;
    private CommitSha $linkedSha;
    private string $unpinnedSha;
    private string $forcePushedSha;

    #[TestDox('fetch --prune changes the heads but keeps the pins')]
    public function testFetchWithPruneChangesTheHeadsButKeepsThePins(): void
    {
        $this->pinThenRewriteTheSource();

        self::assertSame([
            'refs/heads/main' => $this->forcePushedSha,
            PinRefs::commitRef($this->linkedSha) => $this->linkedSha->toString(),
            PinRefs::reviewRef($this->reviewId) => $this->reviewedSha->toString(),
        ], self::cloneRefs($this->clone->directory));
    }

    #[TestDox('git gc --prune=now keeps the pinned commits readable')]
    public function testGcKeepsThePinnedCommitsReadable(): void
    {
        $this->pinThenRewriteTheSource();
        self::assertTrue(self::hasCommit($this->clone->directory, $this->unpinnedSha));

        self::gitRunner()->mustRun(new GitCommand(['gc', '--quiet', '--prune=now'], $this->clone->directory));

        self::assertFalse(self::hasCommit($this->clone->directory, $this->unpinnedSha));
        self::assertSame("reviewed\n", $this->fileAt($this->reviewedSha, 'a.txt'));
        self::assertSame("linked\n", $this->fileAt($this->linkedSha, 'feature.txt'));
    }

    private function pinThenRewriteTheSource(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $base = $source->commit(['a.txt' => "base\n"]);
        $this->reviewedSha = CommitSha::fromString($source->commit(['a.txt' => "reviewed\n"]));
        $source->branch('feature')->checkout('feature');
        $this->linkedSha = CommitSha::fromString($source->commit(['feature.txt' => "linked\n"]));
        $source->branch('topic')->checkout('topic');
        $this->unpinnedSha = $source->commit(['topic.txt' => "unpinned\n"]);
        $source->checkout('main');

        $this->clone = $this->initClone(self::remote($source));
        self::bareClone()->fetch($this->clone->tenantId, $this->clone->repositoryId, self::remote($source));
        $this->reviewId = ReviewId::generate();
        $pinRefs = self::getContainer()->get(PinRefs::class);
        $pinRefs->pinReview($this->clone->tenantId, $this->clone->repositoryId, $this->reviewId, $this->reviewedSha);
        $pinRefs->pinCommit($this->clone->tenantId, $this->clone->repositoryId, $this->linkedSha);

        $source->deleteBranch('feature')->deleteBranch('topic')->resetHard($base);
        $this->forcePushedSha = $source->commit(['a.txt' => "force-pushed\n"]);
        self::bareClone()->fetch($this->clone->tenantId, $this->clone->repositoryId, self::remote($source));
    }

    private function fileAt(CommitSha $commitSha, string $path): string
    {
        return self::gitRunner()->mustRun(new GitCommand(['cat-file', 'blob', '--end-of-options', $commitSha.':'.$path], $this->clone->directory))->output;
    }
}
