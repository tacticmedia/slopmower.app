<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositoryName;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\GitHubCandidate;
use App\Entity\GitHubInstallation;
use App\Entity\Repository;
use App\Entity\Review;
use App\GitHub\GitHubAccountType;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOwned;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class RepositoryScopeSqlFilterTest extends KernelTestCase
{
    use ResetDatabase;
    use ReviewDataFixtures;

    #[TestDox('Under a repository scope, every tenant entity shows only the rows of that repository')]
    public function testEveryTenantEntityIsScoped(): void
    {
        $tenantId = self::enterNewTenant();
        $scenario = RequestScenario::inState('in_progress');
        $requestId = self::persistScenario($tenantId, $scenario);
        $review = self::entityManager()->find(Review::class, Ulid::fromString($scenario->review()->id->toString())) ?? self::fail('No review.');
        $request = self::entityManager()->find(ChangeRequest::class, Ulid::fromString($requestId->toString())) ?? self::fail('No request.');
        $time = new \DateTimeImmutable(self::TIME);
        $other = Repository::local($tenantId, RepositoryName::local('alpha'), 'alpha', $time);
        $gitHub = Repository::gitHub($tenantId, RepositoryName::github('acme/billing'), 812345, 5551, true, $time);
        self::entityManager()->persist(new Comment(CommentId::generate(), $request, RequestScenario::user(), 'Also cover shipping.', false, null, $time));
        self::entityManager()->persist($other);
        self::entityManager()->persist($gitHub);

        foreach ([5551, 5552] as $installationId) {
            self::entityManager()->persist(new GitHubInstallation($tenantId, $installationId, 'acme', GitHubAccountType::Organization, null));
        }

        foreach (['acme/billing' => 812345, 'acme/shipping' => 812399] as $name => $githubRepositoryId) {
            self::entityManager()->persist(new GitHubCandidate($tenantId, $githubRepositoryId, 5551, 'acme', RepositoryName::github($name), true, 'main', "https://github.com/{$name}", $time));
        }

        self::entityManager()->flush();
        self::insertEvent(new RecordedEvent(EventId::generate(), $tenantId, 1, $scenario->review()->id, $requestId, RequestScenario::claimingAgent(), $time, new RequestClaimedEvent()));
        $unscoped = ['ApiToken' => 0, 'User' => 1];
        $noReviewData = ['ChangeRequest' => 0, 'Comment' => 0, 'CommitLink' => 0];

        self::assertSame(['ChangeRequest' => 1, 'Comment' => 1, 'CommitLink' => 1, 'GitHubCandidate' => 0, 'GitHubInstallation' => 0, 'Repository' => 1, 'Review' => 1, 'ReviewEvent' => 1] + $unscoped, self::rowCounts($review->getRepository()->getId()));
        self::assertSame($noReviewData + ['GitHubCandidate' => 0, 'GitHubInstallation' => 0, 'Repository' => 1, 'Review' => 0, 'ReviewEvent' => 0] + $unscoped, self::rowCounts($other->getId()));
        self::assertSame($noReviewData + ['GitHubCandidate' => 1, 'GitHubInstallation' => 1, 'Repository' => 1, 'Review' => 0, 'ReviewEvent' => 0] + $unscoped, self::rowCounts($gitHub->getId()), 'Only its candidate and its installation.');
    }

    /**
     * Counts every mapped tenant entity, so an entity without a scope rule fails here.
     *
     * @return array<string, int> Row count by entity short name, scoped entities first
     */
    private static function rowCounts(RepositoryId $scope): array
    {
        $entityManager = self::entityManager();
        $entityManager->clear();
        self::getContainer()->get(TenantContext::class)->restrictToRepository($scope);
        $counts = [];

        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata->isEmbeddedClass && $metadata->getReflectionClass()->implementsInterface(TenantOwned::class)) {
                $counts[$metadata->getReflectionClass()->getShortName()] = (int) $entityManager->createQuery("SELECT COUNT(row.tenantId) FROM {$metadata->getName()} row")->getSingleScalarResult();
            }
        }

        $unscoped = array_intersect_key($counts, ['ApiToken' => true, 'User' => true]);
        $scoped = array_diff_key($counts, $unscoped);
        ksort($scoped);
        ksort($unscoped);

        return $scoped + $unscoped;
    }
}
