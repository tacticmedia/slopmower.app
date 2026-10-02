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

    public function url(): string
    {
        return 'file://'.$this->path;
    }

    /**
     * @param array<string, string> $files Content by path
     *
     * @return string The new commit SHA
     */
    public function commit(array $files = [], string $message = 'Change'): string
    {
        foreach ($files as $file => $content) {
            new Filesystem()->dumpFile("{$this->path}/{$file}", $content);
        }

        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--allow-empty', '--message='.$message);

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

    public function revParse(string $revision): string
    {
        return trim($this->git('rev-parse', '--verify', '--end-of-options', $revision));
    }

    public function git(string ...$arguments): string
    {
        return new Process(['git', ...$arguments], $this->path, self::ENVIRONMENT)->mustRun()->getOutput();
    }
}
