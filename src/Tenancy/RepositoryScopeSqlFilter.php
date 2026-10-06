<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\ApiToken;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Draft;
use App\Entity\GitHubCandidate;
use App\Entity\GitHubInstallation;
use App\Entity\IndexedBlob;
use App\Entity\IndexedFile;
use App\Entity\LocationCacheEntry;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\ReviewEvent;
use App\Entity\Symbol;
use App\Entity\TenantOwned;
use App\Entity\User;
use App\Entity\ViewedFile;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Enabled only for a bearer request of a token scoped to one repository. Rows of other repositories do not exist for
 * that request, so their IDs answer 404. Fails closed for a tenant entity without a rule. Like {@see TenantSqlFilter},
 * it does not cover DBAL queries or identity-map hits.
 */
final class RepositoryScopeSqlFilter extends SQLFilter
{
    public const string NAME = 'repository_scope';
    public const string PARAMETER = 'repositoryId';

    /** Tenant entities that a scoped token reads outside its repository: its owner and its own row. */
    public const array UNSCOPED = [User::class, ApiToken::class];

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $class = $targetEntity->getName();

        if (!$targetEntity->getReflectionClass()->implementsInterface(TenantOwned::class) || in_array($class, self::UNSCOPED, true)) {
            return '';
        }

        $repositoryId = $this->getParameter(self::PARAMETER);
        $reviewsOfRepository = "SELECT scope_review.id FROM review scope_review WHERE scope_review.repository_id = {$repositoryId}";
        $repositoryColumn = static fn (string $column): string => "SELECT scope_repository.{$column} FROM repository scope_repository WHERE scope_repository.id = {$repositoryId}";

        return match ($class) {
            Repository::class => "{$targetTableAlias}.id = {$repositoryId}",
            Review::class, IndexedFile::class, IndexedBlob::class, Symbol::class, LocationCacheEntry::class => "{$targetTableAlias}.repository_id = {$repositoryId}",
            GitHubInstallation::class => "{$targetTableAlias}.installation_id IN ({$repositoryColumn('github_installation_id')})",
            GitHubCandidate::class => "{$targetTableAlias}.github_repository_id IN ({$repositoryColumn('github_repository_id')})",
            ChangeRequest::class, ReviewEvent::class, Draft::class, ViewedFile::class => "{$targetTableAlias}.review_id IN ({$reviewsOfRepository})",
            Comment::class, CommitLink::class => "{$targetTableAlias}.request_id IN (SELECT scope_request.id FROM change_request scope_request WHERE scope_request.review_id IN ({$reviewsOfRepository}))",
            default => throw new \LogicException("The repository scope has no rule for {$class}."),
        };
    }
}
