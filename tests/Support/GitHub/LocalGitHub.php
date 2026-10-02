<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use App\Tests\Support\Git\GitRepositoryBuilder;

/**
 * GitHub clone URLs that point at bare repositories in a temporary directory, for KernelTestCase tests that use
 * EnvironmentVariables and TemporaryDirectories. {@see useLocalGitHub()} runs before the kernel boots.
 */
trait LocalGitHub
{
    private string $gitHubDirectory;

    private string $tenantDataDirectory;

    private function useLocalGitHub(): void
    {
        $this->gitHubDirectory = $this->temporaryDirectory();
        $this->tenantDataDirectory = $this->temporaryDirectory();
        $this->setEnvironmentVariable('GITHUB_GIT_BASE_URL', 'file://'.$this->gitHubDirectory);
        $this->setEnvironmentVariable('APP_TENANT_DATA_DIR', $this->tenantDataDirectory);
    }

    /**
     * A working copy with one commit on main, and its bare copy at the clone URL of the name.
     */
    private function gitHubSource(string $fullName = 'acme/billing'): GitRepositoryBuilder
    {
        $work = GitRepositoryBuilder::init($this->temporaryDirectory());
        $work->commit(['README.md' => "Hello\n"]);
        $work->git('clone', '--quiet', '--bare', $work->path, "{$this->gitHubDirectory}/{$fullName}.git");
        $work->git('remote', 'add', 'origin', "{$this->gitHubDirectory}/{$fullName}.git");

        return $work;
    }
}
