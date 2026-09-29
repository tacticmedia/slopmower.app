<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Git\BareClone;
use App\Git\PinRefs;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Git\ServerClones;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PendingShaFetchTest extends KernelTestCase
{
    use ServerClones;

    private const string UNKNOWN_SHA = '0123456789abcdef0123456789abcdef01234567';

    /**
     * @return iterable<string, array{\Closure(GitRepositoryBuilder): string}>
     */
    public static function sourceCommits(): iterable
    {
        yield 'a commit on a branch' => [static fn (GitRepositoryBuilder $source): string => self::commitOnBranch($source, 'feature')];
        yield 'a commit that only refs/pull/1/head reaches' => [static fn (GitRepositoryBuilder $source): string => self::commitOnlyInPullRef($source)];
        yield 'a commit that no ref reaches' => [static function (GitRepositoryBuilder $source): string {
            $sha = self::commitOnBranch($source, 'dangling');
            $source->deleteBranch('dangling');

            return $sha;
        }];
    }

    /**
     * @param \Closure(GitRepositoryBuilder): string $createCommit
     */
    #[DataProvider('sourceCommits')]
    #[TestDox('A pending SHA that the source has lands in refs/holistic-review/commits')]
    public function testAPendingShaThatTheSourceHasLandsInItsCommitPin(\Closure $createCommit): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['a.txt' => "a\n"]);
        $pendingSha = CommitSha::fromString($createCommit($source));
        $clone = $this->initClone(self::remote($source));

        $result = self::bareClone()->fetch($clone->tenantId, $clone->repositoryId, self::remote($source), [$pendingSha]);

        self::assertEquals([$pendingSha], $result->fetchedPendingShas);
        self::assertSame($pendingSha->toString(), self::cloneRefs($clone->directory)[PinRefs::commitRef($pendingSha)] ?? null);
        self::assertTrue(self::hasCommit($clone->directory, $pendingSha->toString()));
    }

    #[TestDox('An unknown pending SHA does not stop the heads or the other pending SHAs')]
    public function testAnUnknownPendingShaDoesNotStopTheOthers(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['a.txt' => "a\n"]);
        $pullSha = CommitSha::fromString(self::commitOnlyInPullRef($source));
        $branchSha = CommitSha::fromString(self::commitOnBranch($source, 'feature'));
        $clone = $this->initClone(self::remote($source));

        $result = self::bareClone()->fetch($clone->tenantId, $clone->repositoryId, self::remote($source), [$pullSha, CommitSha::fromString(self::UNKNOWN_SHA), $branchSha, $pullSha]);

        self::assertEquals([$pullSha, $branchSha], $result->fetchedPendingShas);
        $expectedRefs = self::sourceHeadsAndTags($source) + [
            PinRefs::commitRef($pullSha) => $pullSha->toString(),
            PinRefs::commitRef($branchSha) => $branchSha->toString(),
        ];
        ksort($expectedRefs, \SORT_STRING);
        self::assertSame($expectedRefs, self::cloneRefs($clone->directory));
    }

    #[TestDox('One unknown SHA fails the whole fetch by ID with exit code 128, and git writes no ref')]
    public function testOneUnknownShaFailsTheWholeFetchById(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $knownSha = CommitSha::fromString($source->commit(['a.txt' => "a\n"]));
        $clone = $this->initClone(self::remote($source));

        $result = self::gitRunner()->run(BareClone::pendingShasFetchCommand(
            $clone->directory,
            self::remote($source),
            [$knownSha, CommitSha::fromString(self::UNKNOWN_SHA)],
        ));

        self::assertSame(128, $result->exitCode);
        self::assertStringContainsString('not our ref '.self::UNKNOWN_SHA, $result->errorOutput);
        self::assertSame([], self::cloneRefs($clone->directory));
    }

    private static function commitOnBranch(GitRepositoryBuilder $source, string $branch): string
    {
        $source->branch($branch)->checkout($branch);
        $sha = $source->commit([$branch.'.txt' => $branch."\n"]);
        $source->checkout('main');

        return $sha;
    }

    private static function commitOnlyInPullRef(GitRepositoryBuilder $source): string
    {
        $sha = self::commitOnBranch($source, 'pull');
        $source->updateRef('refs/pull/1/head', $sha)->deleteBranch('pull');

        return $sha;
    }
}
