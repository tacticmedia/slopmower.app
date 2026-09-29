<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Git\GitCommandFailed;
use App\Git\GitReader;
use App\Git\GitRunner;
use App\Git\TenantDataPaths;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GitRunner logs every git process, so an empty git log proves that git did not run.
 */
final class RefInjectionTest extends KernelTestCase
{
    private const string COMMIT_SHA = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';

    /**
     * @return iterable<string, array{string}>
     */
    public static function optionLikeRefs(): iterable
    {
        yield '--upload-pack=x' => ['--upload-pack=x'];
        yield '-h' => ['-h'];
        yield 'a..b' => ['a..b'];
        yield 'HEAD@{1}' => ['HEAD@{1}'];
    }

    #[DataProvider('optionLikeRefs')]
    #[TestDox('Rejects --upload-pack=x, -h, a..b, and HEAD@{1} before git runs')]
    public function testRejectsOptionLikeRefsBeforeGitRuns(string $ref): void
    {
        $gitLog = new TestHandler();
        $reader = self::reader($gitLog);

        try {
            $reader->resolveCommit(TenantId::generate(), RepositoryId::generate(), RefName::fromString($ref));
            self::fail('The ref was accepted.');
        } catch (InvalidInput $invalidInput) {
            self::assertSame('ref', $invalidInput->violations()[0]->field);
        }
        self::assertSame([], $gitLog->getRecords());
    }

    /**
     * @return iterable<string, array{\Closure(GitReader): mixed, string}>
     */
    public static function optionLikePrefixesAndPaths(): iterable
    {
        foreach (['--upload-pack=x', '-h'] as $value) {
            yield 'prefix '.$value => [
                static fn (GitReader $reader): mixed => $reader->resolvePrefix(TenantId::generate(), RepositoryId::generate(), CommitShaPrefix::fromString($value)),
                'commit',
            ];
            yield 'path '.$value => [
                static fn (GitReader $reader): mixed => $reader->entryAt(TenantId::generate(), RepositoryId::generate(), CommitSha::fromString(self::COMMIT_SHA), RepoPath::fromString($value)),
                'path',
            ];
        }
    }

    /**
     * @param \Closure(GitReader): mixed $read
     */
    #[DataProvider('optionLikePrefixesAndPaths')]
    #[TestDox('Rejects option-like SHA prefixes and paths before git runs')]
    public function testRejectsOptionLikePrefixesAndPathsBeforeGitRuns(\Closure $read, string $expectedField): void
    {
        $gitLog = new TestHandler();

        try {
            $read(self::reader($gitLog));
            self::fail('The value was accepted.');
        } catch (InvalidInput $invalidInput) {
            self::assertSame($expectedField, $invalidInput->violations()[0]->field);
        }
        self::assertSame([], $gitLog->getRecords());
    }

    #[TestDox('Logs git for a valid ref, so an empty log means that git did not run')]
    public function testLogsGitForAValidRef(): void
    {
        $gitLog = new TestHandler();

        try {
            self::reader($gitLog)->resolveCommit(TenantId::generate(), RepositoryId::generate(), RefName::fromString('main'));
            self::fail('Git ran in a clone that does not exist.');
        } catch (GitCommandFailed $failure) {
            self::assertStringContainsString('git rev-parse --verify --quiet --end-of-options main^{commit} did not run', $failure->getMessage());
        }
        self::assertNotSame([], $gitLog->getRecords());
    }

    private static function reader(TestHandler $gitLog): GitReader
    {
        return new GitReader(new GitRunner(new Logger('git', [$gitLog])), new TenantDataPaths('/nonexistent/tenants', '/nonexistent'));
    }
}
