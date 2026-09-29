<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * The `<data>/tenants` layout of docs/specs section 7. Paths come only from typed IDs.
 */
final class TenantDataPaths
{
    private readonly string $root;

    /**
     * @param string $tenantDataDirectory a relative value resolves against the project directory, never the working directory
     */
    public function __construct(
        #[Autowire(env: 'APP_TENANT_DATA_DIR')]
        string $tenantDataDirectory,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDirectory,
    ) {
        if ('' === trim($tenantDataDirectory)) {
            throw new \InvalidArgumentException('APP_TENANT_DATA_DIR must not be empty.');
        }

        $this->root = Path::makeAbsolute($tenantDataDirectory, $projectDirectory);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function tenantDirectory(TenantId $tenantId): string
    {
        return $this->underRoot($this->root.'/'.$tenantId->toString());
    }

    public function cloneDirectory(TenantId $tenantId, RepositoryId $repositoryId): string
    {
        return $this->underRoot($this->tenantDirectory($tenantId).'/repositories/'.$repositoryId->toString().'.git');
    }

    private function underRoot(string $path): string
    {
        $canonicalPath = Path::canonicalize($path);
        if ($canonicalPath === $this->root || !Path::isBasePath($this->root, $canonicalPath)) {
            throw new \LogicException(\sprintf('The path "%s" is not under the tenant data root "%s".', $path, $this->root));
        }

        return $canonicalPath;
    }
}
