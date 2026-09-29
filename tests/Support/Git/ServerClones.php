<?php

declare(strict_types=1);

namespace App\Tests\Support\Git;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Git\BareClone;
use App\Git\GitCommand;
use App\Git\GitRemote;
use App\Git\GitRunner;
use App\Git\TenantDataPaths;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Server clones at their tenant data path. Each tenant directory is removed after the test.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait ServerClones
{
    use TemporaryDirectories;

    /**
     * An initialized clone, not fetched yet.
     */
    private function initClone(GitRemote $remote): CloneLocation
    {
        $paths = self::getContainer()->get(TenantDataPaths::class);
        $tenantId = TenantId::generate();
        $repositoryId = RepositoryId::generate();
        $this->removeAfterTest($paths->tenantDirectory($tenantId));
        self::bareClone()->init($tenantId, $repositoryId, $remote);

        return new CloneLocation($tenantId, $repositoryId, $paths->cloneDirectory($tenantId, $repositoryId));
    }

    private static function remote(GitRepositoryBuilder $source): GitRemote
    {
        return new GitRemote($source->fileUrl());
    }

    private static function bareClone(): BareClone
    {
        return self::getContainer()->get(BareClone::class);
    }

    private static function gitRunner(): GitRunner
    {
        return self::getContainer()->get(GitRunner::class);
    }

    /**
     * @return array<string, string> the object ID of each ref by ref name
     */
    private static function cloneRefs(string $cloneDirectory): array
    {
        return self::parseRefs(self::gitRunner()->mustRun(new GitCommand(['for-each-ref', '--format=%(refname) %(objectname)'], $cloneDirectory))->output);
    }

    /**
     * @return array<string, string> the object ID of each head and tag by ref name
     */
    private static function sourceHeadsAndTags(GitRepositoryBuilder $source): array
    {
        return self::parseRefs($source->git('for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads/', 'refs/tags/'));
    }

    private static function hasCommit(string $cloneDirectory, string $sha): bool
    {
        return self::gitRunner()->run(new GitCommand(['cat-file', '-e', '--end-of-options', $sha.'^{commit}'], $cloneDirectory))->isSuccessful();
    }

    /**
     * @return array<string, string>
     */
    private static function parseRefs(string $forEachRefOutput): array
    {
        $refs = [];
        foreach (explode("\n", rtrim($forEachRefOutput, "\n")) as $line) {
            if ('' !== $line) {
                [$ref, $objectId] = explode(' ', $line, 2);
                $refs[$ref] = $objectId;
            }
        }

        return $refs;
    }
}
