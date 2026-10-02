<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

final class TenantDataPaths
{
    private readonly string $root;

    public function __construct(
        #[Autowire(env: 'APP_TENANT_DATA_DIR')]
        string $tenantDataDir,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $this->root = Path::makeAbsolute($tenantDataDir, $projectDir);
    }

    public function clonePath(TenantId $tenantId, RepositoryId $repositoryId): string
    {
        $path = Path::join($this->root, $tenantId->toString(), 'repositories', $repositoryId->toString().'.git');

        if (!Path::isBasePath($this->root, $path) || $path === $this->root) {
            throw new \LogicException("The path {$path} is not under {$this->root}.");
        }

        return $path;
    }
}
