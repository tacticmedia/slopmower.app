<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\ApiToken\TokenScope;
use App\Domain\Identity\RepositoryId;

/**
 * The repositories that an API token may see. {@see \App\Tenancy\RepositoryScopeSqlFilter} enforces a repository scope.
 */
final readonly class AccessScope
{
    private function __construct(public ?RepositoryId $repositoryId)
    {
    }

    public static function allRepositories(): self
    {
        return new self(null);
    }

    public static function repository(RepositoryId $repositoryId): self
    {
        return new self($repositoryId);
    }

    public function kind(): TokenScope
    {
        return null === $this->repositoryId ? TokenScope::AllRepositories : TokenScope::Repository;
    }
}
