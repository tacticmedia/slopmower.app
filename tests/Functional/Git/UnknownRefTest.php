<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\ErrorCode;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Git\GitReader;
use App\Git\GitRunner;
use App\Git\TenantDataPaths;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnknownRefTest extends KernelTestCase
{
    use TemporaryDirectories;

    #[TestDox('An unknown ref gives commit_not_found')]
    public function testAnUnknownRefGivesCommitNotFound(): void
    {
        $tenantId = TenantId::generate();
        $repositoryId = RepositoryId::generate();
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['a.txt' => "a\n"]);
        $source->tag('v1.0');
        $blobPrefix = substr($source->resolve('HEAD:a.txt'), 0, 7);
        $paths = self::getContainer()->get(TenantDataPaths::class);
        $this->removeAfterTest($paths->tenantDirectory($tenantId));
        $source->git('clone', '--bare', '--quiet', $source->fileUrl(), $paths->cloneDirectory($tenantId, $repositoryId));
        $reader = new GitReader(self::getContainer()->get(GitRunner::class), $paths);

        $resolutions = [];
        foreach (['feature', 'v2.0', 'refs/heads/v1.0', str_repeat('f', 40), 'fffffff', $blobPrefix] as $ref) {
            $resolutions['ref '.$ref] = static fn () => $reader->resolveCommit($tenantId, $repositoryId, RefName::fromString($ref));
        }
        foreach (['fffffff', $blobPrefix] as $prefix) {
            $resolutions['prefix '.$prefix] = static fn () => $reader->resolvePrefix($tenantId, $repositoryId, CommitShaPrefix::fromString($prefix));
        }

        foreach ($resolutions as $case => $resolution) {
            try {
                $resolution();
                self::fail(\sprintf('The %s was resolved.', $case));
            } catch (CommitNotFound $commitNotFound) {
                self::assertSame(ErrorCode::CommitNotFound, $commitNotFound->code(), $case);
                self::assertSame(['commit' => explode(' ', $case, 2)[1]], $commitNotFound->details(), $case);
            }
        }
    }
}
