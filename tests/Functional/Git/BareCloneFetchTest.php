<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Git\BareClone;
use App\Git\FetchResult;
use App\Git\GitCommand;
use App\Git\GitCommandFailed;
use App\Git\GitRemote;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Tests\Support\Git\CloneLocation;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Git\ServerClones;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BareCloneFetchTest extends KernelTestCase
{
    use ServerClones;

    #[TestDox('After a fetch only refs/heads, refs/tags, and refs/holistic-review exist')]
    public function testFetchesOnlyHeadsTagsAndPins(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['README.md' => "# Title\n"]);
        $source->branch('feature')->checkout('feature');
        $featureTip = $source->commit(['feature.txt' => "feature\n"]);
        $source->checkout('main')->tag('v1.0')->tag('v1.1', 'feature', 'Release 1.1');
        $source->branch('pull')->checkout('pull');
        $pullTip = $source->commit(['pull.txt' => "pull\n"]);
        $source->checkout('main')->deleteBranch('pull')
            ->updateRef('refs/pull/1/head', $pullTip)
            ->updateRef('refs/remotes/origin/main', 'main')
            ->updateRef('refs/notes/commits', $featureTip);
        $clone = $this->initClone(self::remote($source));

        self::bareClone()->fetch($clone->tenantId, $clone->repositoryId, self::remote($source), [CommitSha::fromString($pullTip)]);

        $expectedRefs = self::sourceHeadsAndTags($source) + [PinRefs::commitRef(CommitSha::fromString($pullTip)) => $pullTip];
        ksort($expectedRefs, \SORT_STRING);
        self::assertSame($expectedRefs, self::cloneRefs($clone->directory));
    }

    #[TestDox('Points HEAD to the default branch of the source, and keeps it while the source HEAD is detached')]
    public function testPointsHeadToTheDefaultBranchOfTheSource(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'), 'trunk');
        $source->commit(['a.txt' => "a\n"]);
        $source->branch('develop');
        $clone = $this->initClone(self::remote($source));

        self::assertSame('trunk', self::fetch($clone, $source)->defaultBranch?->toString());
        self::assertSame('refs/heads/trunk', self::cloneHead($clone));

        $source->checkout('develop');
        self::assertSame('develop', self::fetch($clone, $source)->defaultBranch?->toString());
        self::assertSame('refs/heads/develop', self::cloneHead($clone));

        $source->git('checkout', '--quiet', '--detach');
        self::assertNull(self::fetch($clone, $source)->defaultBranch);
        self::assertSame('refs/heads/develop', self::cloneHead($clone));
    }

    #[TestDox('Creates the clone with the object format of a SHA-256 source')]
    public function testCreatesTheCloneWithTheObjectFormatOfTheSource(): void
    {
        $sourceDirectory = $this->createTemporaryDirectory('source');
        self::gitRunner()->mustRun(new GitCommand(['init', '--quiet', '--object-format=sha256', '--initial-branch=main'], $sourceDirectory));
        self::gitRunner()->mustRun(new GitCommand(
            ['-c', 'user.name=Test Author', '-c', 'user.email=author@example.com', 'commit', '--quiet', '--allow-empty', '--message=First'],
            $sourceDirectory,
        ));
        $sourceHead = trim(self::gitRunner()->mustRun(new GitCommand(['rev-parse', 'HEAD'], $sourceDirectory))->output);
        $remote = new GitRemote('file://'.$sourceDirectory);
        $clone = $this->initClone($remote);

        self::bareClone()->fetch($clone->tenantId, $clone->repositoryId, $remote);

        self::assertSame("sha256\n", self::gitRunner()->mustRun(new GitCommand(['rev-parse', '--show-object-format'], $clone->directory))->output);
        self::assertSame(['refs/heads/main' => $sourceHead], self::cloneRefs($clone->directory));
        self::assertSame(64, \strlen($sourceHead));
    }

    #[TestDox('Stops a fetch when its timeout for all git commands together runs out')]
    public function testStopsAFetchWhenItsTimeoutRunsOut(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['a.txt' => "a\n"]);
        $clone = $this->initClone(self::remote($source));
        // With 29 seconds and 10 seconds per reading, ls-remote gets 19 seconds, the fetch 9, and symbolic-ref none.
        $clock = new class implements ClockInterface {
            private int $readingCount = 0;

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@'.(1767225600 + 10 * $this->readingCount++));
            }
        };
        $bareClone = new BareClone(self::gitRunner(), self::getContainer()->get(TenantDataPaths::class), $clock);

        try {
            $bareClone->fetch($clone->tenantId, $clone->repositoryId, self::remote($source), [], 29.0);
            self::fail('The fetch did not stop.');
        } catch (GitCommandFailed $failure) {
            self::assertStringStartsWith('git symbolic-ref --end-of-options HEAD refs/heads/main exceeded the timeout of 29 seconds', $failure->getMessage());
        }
        self::assertSame(self::sourceHeadsAndTags($source), self::cloneRefs($clone->directory));
        self::assertNotSame('refs/heads/main', self::cloneHead($clone));
    }

    #[TestDox('A failed heads fetch throws and leaves the refs of the clone unchanged')]
    public function testAFailedHeadsFetchThrowsAndLeavesTheRefsUnchanged(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['a.txt' => "a\n"]);
        $clone = $this->initClone(self::remote($source));
        self::fetch($clone, $source);
        $refsAfterFirstFetch = self::cloneRefs($clone->directory);
        $source->commit(['b.txt' => "b\n"]);
        $blob = $source->resolve('HEAD:b.txt');
        new Filesystem()->remove($source->directory().'/.git/objects/'.substr($blob, 0, 2).'/'.substr($blob, 2));

        try {
            self::fetch($clone, $source);
            self::fail('The fetch did not fail.');
        } catch (GitCommandFailed $failure) {
            self::assertSame(128, $failure->exitCode);
            self::assertStringStartsWith('git fetch --no-auto-maintenance --prune ', $failure->getMessage());
        }
        self::assertNotSame(self::sourceHeadsAndTags($source), $refsAfterFirstFetch);
        self::assertSame($refsAfterFirstFetch, self::cloneRefs($clone->directory));
    }

    private static function fetch(CloneLocation $clone, GitRepositoryBuilder $source): FetchResult
    {
        return self::bareClone()->fetch($clone->tenantId, $clone->repositoryId, self::remote($source));
    }

    private static function cloneHead(CloneLocation $clone): string
    {
        return trim(self::gitRunner()->mustRun(new GitCommand(['symbolic-ref', 'HEAD'], $clone->directory))->output);
    }
}
