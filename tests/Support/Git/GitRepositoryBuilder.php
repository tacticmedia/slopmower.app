<?php

declare(strict_types=1);

namespace App\Tests\Support\Git;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * A source repository with a working tree. Commits get fixed authors and dates, so equal histories get equal SHAs.
 * Use it in a directory of {@see \App\Tests\Support\Filesystem\TemporaryDirectories}.
 */
final class GitRepositoryBuilder
{
    // 2026-01-01T00:00:00Z. Each commit and annotated tag is one second later.
    private const int FIRST_TIMESTAMP = 1767225600;

    private const array ENVIRONMENT = [
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_TERMINAL_PROMPT' => '0',
        'LC_ALL' => 'C',
        'GIT_AUTHOR_NAME' => 'Test Author',
        'GIT_AUTHOR_EMAIL' => 'author@example.com',
        'GIT_COMMITTER_NAME' => 'Test Committer',
        'GIT_COMMITTER_EMAIL' => 'committer@example.com',
    ];

    private int $timestampCount = 0;

    private function __construct(private readonly string $directory)
    {
    }

    public static function init(string $directory, string $initialBranch = 'main'): self
    {
        new Filesystem()->mkdir($directory);
        $builder = new self($directory);
        $builder->git('init', '--quiet', '--initial-branch='.$initialBranch);

        return $builder;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The fetch URL of a local source: `file://` and the real path.
     */
    public function fileUrl(): string
    {
        $realPath = realpath($this->directory);
        if (false === $realPath) {
            throw new \RuntimeException(\sprintf('%s does not exist.', $this->directory));
        }

        return 'file://'.$realPath;
    }

    /**
     * Commits on the checked-out branch.
     *
     * @param array<string, string|null> $files contents by path relative to the repository root, null deletes the file
     *
     * @return string the SHA of the new commit
     */
    public function commit(array $files, string $message = 'Change files'): string
    {
        $filesystem = new Filesystem();
        foreach ($files as $path => $contents) {
            $absolutePath = $this->directory.'/'.$path;
            if (null === $contents) {
                $filesystem->remove($absolutePath);
            } else {
                $filesystem->dumpFile($absolutePath, $contents);
            }
        }

        $this->git('add', '--all');
        $this->runGit(['commit', '--quiet', '--allow-empty', '--message='.$message], $this->nextDates());

        return $this->resolve('HEAD');
    }

    /**
     * Creates the branch, or moves it when it exists and is not checked out.
     */
    public function branch(string $name, string $startPoint = 'HEAD'): self
    {
        $this->git('branch', '--quiet', '--no-track', '--force', $name, $startPoint);

        return $this;
    }

    public function checkout(string $branch): self
    {
        $this->git('checkout', '--quiet', $branch);

        return $this;
    }

    /**
     * Moves the checked-out branch, for example before a commit that a force-push would publish.
     */
    public function resetHard(string $target): self
    {
        $this->git('reset', '--quiet', '--hard', $target);

        return $this;
    }

    public function deleteBranch(string $name): self
    {
        $this->git('branch', '--quiet', '--delete', '--force', $name);

        return $this;
    }

    /**
     * A lightweight tag, or an annotated tag when a message is given.
     */
    public function tag(string $name, string $target = 'HEAD', ?string $message = null): self
    {
        if (null === $message) {
            $this->git('tag', $name, $target);
        } else {
            $this->runGit(['tag', '--annotate', '--message='.$message, $name, $target], $this->nextDates());
        }

        return $this;
    }

    /**
     * Any ref, for example `refs/pull/1/head` outside `refs/heads`.
     */
    public function updateRef(string $ref, string $target): self
    {
        $this->git('update-ref', $ref, $target);

        return $this;
    }

    /**
     * @return string the full object ID
     */
    public function resolve(string $revision): string
    {
        return trim($this->git('rev-parse', '--verify', '--end-of-options', $revision));
    }

    /**
     * @return string the standard output
     */
    public function git(string ...$arguments): string
    {
        return $this->runGit(array_values($arguments));
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function runGit(array $arguments, array $environment = []): string
    {
        return new Process(['git', ...$arguments], $this->directory, $environment + self::ENVIRONMENT)->mustRun()->getOutput();
    }

    /**
     * @return array{GIT_AUTHOR_DATE: string, GIT_COMMITTER_DATE: string}
     */
    private function nextDates(): array
    {
        $date = '@'.(self::FIRST_TIMESTAMP + $this->timestampCount++).' +0000';

        return ['GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date];
    }
}
