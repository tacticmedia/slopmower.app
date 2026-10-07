<?php

declare(strict_types=1);

namespace App\Tests\Support\Git;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * A source repository with a working tree, on the branch main.
 */
final class GitRepositoryBuilder
{
    /** The commits of {@see branchCommitsWithAmbiguousPrefix()} share this SHA prefix. */
    public const string AMBIGUOUS_PREFIX = '0806e1e';

    private const array ENVIRONMENT = [
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_AUTHOR_NAME' => 'Test',
        'GIT_AUTHOR_EMAIL' => 'test@example.invalid',
        'GIT_COMMITTER_NAME' => 'Test',
        'GIT_COMMITTER_EMAIL' => 'test@example.invalid',
    ];

    private function __construct(public readonly string $path)
    {
    }

    public static function init(string $path): self
    {
        new Filesystem()->mkdir($path);
        $repository = new self($path);
        $repository->git('init', '--quiet', '--initial-branch=main');

        return $repository;
    }

    /**
     * A repository that {@see init()} created earlier.
     */
    public static function open(string $path): self
    {
        return new self($path);
    }

    public function url(): string
    {
        return 'file://'.$this->path;
    }

    /**
     * @param array<string, string> $files  Content by path
     * @param string|null           $author `Name <email>`, instead of the Test identity
     * @param string|null           $date   The author date, for example `2026-01-02T03:04:05Z`
     *
     * @return string The new commit SHA
     */
    public function commit(array $files = [], string $message = 'Change', ?string $author = null, ?string $date = null): string
    {
        foreach ($files as $file => $content) {
            new Filesystem()->dumpFile("{$this->path}/{$file}", $content);
        }

        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--allow-empty', '--message='.$message, ...(null === $author ? [] : ['--author='.$author]), ...(null === $date ? [] : ['--date='.$date]));

        return $this->revParse('HEAD');
    }

    /**
     * Switches to the branch and creates it at HEAD when it does not exist.
     */
    public function switch(string $branch): self
    {
        $exists = '' !== $this->git('branch', '--list', $branch);
        $this->git('switch', '--quiet', ...($exists ? [$branch] : ['--create', $branch]));

        return $this;
    }

    /**
     * The branches `first` and `second`, each with one commit on the empty tree. Fixed dates make the SHAs start with
     * {@see AMBIGUOUS_PREFIX}.
     */
    public function branchCommitsWithAmbiguousPrefix(): void
    {
        $emptyTree = trim($this->gitWithInput('', 'hash-object', '-t', 'tree', '-w', '--stdin'));

        foreach (['first' => 'Commit 27811', 'second' => 'Commit 29253'] as $branch => $message) {
            $commit = trim($this->gitWithInput(
                "tree {$emptyTree}\nauthor Test <test@example.invalid> 1700000000 +0000\ncommitter Test <test@example.invalid> 1700000000 +0000\n\n{$message}\n",
                'hash-object', '-t', 'commit', '-w', '--stdin',
            ));
            $this->git('branch', $branch, $commit);
        }
    }

    public function revParse(string $revision): string
    {
        return trim($this->git('rev-parse', '--verify', '--end-of-options', $revision));
    }

    public function git(string ...$arguments): string
    {
        return $this->gitWithInput(null, ...$arguments);
    }

    private function gitWithInput(?string $input, string ...$arguments): string
    {
        return new Process(['git', ...$arguments], $this->path, self::ENVIRONMENT, $input)->mustRun()->getOutput();
    }
}
