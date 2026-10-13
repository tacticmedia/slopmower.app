<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Anchoring\LocationCacheSql;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Location\Location;
use App\Domain\Repository\RepositoryName;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\Draft;
use App\Entity\GitHubAccountType;
use App\Entity\GitHubCandidate;
use App\Entity\GitHubInstallation;
use App\Entity\PullRequest;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\TenantOwned;
use App\Entity\User;
use App\Entity\ViewedFile;
use App\Git\ObjectType;
use App\Git\TreeEntry;
use App\Index\ExtractedSymbol;
use App\Index\IndexSql;
use App\Index\SymbolTable;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
        $review = self::entityManager()->find(Review::class, $scenario->review()->id) ?? self::fail('No review.');
        $request = self::entityManager()->find(ChangeRequest::class, $requestId) ?? self::fail('No request.');
        $time = new \DateTimeImmutable(self::TIME);
        $other = Repository::local($tenantId, RepositoryName::local('alpha'), 'alpha', $time);
        $gitHub = Repository::gitHub($tenantId, RepositoryName::github('acme/billing'), 812345, 5551, true, $time);
        self::entityManager()->persist(new Comment(CommentId::generate(), $request, RequestScenario::user(), 'Also cover shipping.', false, null, $time));
        $user = self::entityManager()->getRepository(User::class)->findOneBy([]) ?? self::fail('No user.');
        self::entityManager()->persist(Draft::reply($user, $request, 'Also cover refunds.', $time));
        self::entityManager()->persist(new ViewedFile($user, $review, RepoPath::fromString('README.md')));
        self::entityManager()->persist($other);
        self::entityManager()->persist($gitHub);
        self::entityManager()->persist(new PullRequest($gitHub, 42));

        foreach ([5551, 5552] as $installationId) {
            self::entityManager()->persist(new GitHubInstallation($tenantId, $installationId, 'acme', GitHubAccountType::Organization, null, ['contents' => 'read', 'metadata' => 'read']));
        }

        foreach (['acme/billing' => 812345, 'acme/shipping' => 812399] as $name => $githubRepositoryId) {
            self::entityManager()->persist(new GitHubCandidate($tenantId, $githubRepositoryId, 5551, 'acme', RepositoryName::github($name), true, 'main', "https://github.com/{$name}", $time));
        }

        self::entityManager()->flush();
        $eventId = EventId::generate();
        self::insertEvent(new RecordedEvent($eventId, $tenantId, 1, $scenario->review()->id, $requestId, RequestScenario::claimingAgent(), $time, new RequestClaimedEvent()));
        self::insertIndexRows($review);
        self::getContainer()->get(LocationCacheSql::class)->insert($tenantId, $review->getRepository()->getId(), $eventId, Location::exact($review->getCommitSha(), RepoPath::fromString('src/Totals.php'), null));
        $unscoped = ['ApiToken' => 0, 'User' => 1];
        $noReviewData = ['ChangeRequest' => 0, 'Comment' => 0, 'CommitLink' => 0, 'Draft' => 0];
        $noIndex = ['IndexedBlob' => 0, 'IndexedFile' => 0, 'LocationCacheEntry' => 0];

        self::assertSame(['ChangeRequest' => 1, 'Comment' => 1, 'CommitLink' => 1, 'Draft' => 1, 'GitHubCandidate' => 0, 'GitHubInstallation' => 0, 'IndexedBlob' => 1, 'IndexedFile' => 1, 'LocationCacheEntry' => 1, 'PullRequest' => 0, 'Repository' => 1, 'Review' => 1, 'ReviewEvent' => 1, 'Symbol' => 1, 'ViewedFile' => 1] + $unscoped, self::rowCounts($review->getRepository()->getId()));
        self::assertSame($noReviewData + ['GitHubCandidate' => 0, 'GitHubInstallation' => 0] + $noIndex + ['PullRequest' => 0, 'Repository' => 1, 'Review' => 0, 'ReviewEvent' => 0, 'Symbol' => 0, 'ViewedFile' => 0] + $unscoped, self::rowCounts($other->getId()));
        self::assertSame($noReviewData + ['GitHubCandidate' => 1, 'GitHubInstallation' => 1] + $noIndex + ['PullRequest' => 1, 'Repository' => 1, 'Review' => 0, 'ReviewEvent' => 0, 'Symbol' => 0, 'ViewedFile' => 0] + $unscoped, self::rowCounts($gitHub->getId()), 'Only its candidate, its installation, and its pull request.');
    }

    /**
     * One indexed file of the review commit, its blob, and one symbol.
     */
    private static function insertIndexRows(Review $review): void
    {
        $repository = $review->getRepository();
        $blobSha = 'e965047ad7c57865823c7d992b1d046ea66edf78';
        $indexSql = self::getContainer()->get(IndexSql::class);
        $indexSql->insertFiles($repository->getTenantId(), $repository->getId(), $review->getCommitSha(), [new TreeEntry('src/Totals.php', '100644', ObjectType::Blob, $blobSha, 20)]);
        $symbol = new ExtractedSymbol(SymbolKind::Class_, 'App\Totals', 'Totals', null, LineRange::of(3, 5), 3, null);
        $indexSql->insertBlobs($repository->getTenantId(), $repository->getId(), [$blobSha => new SymbolTable('php', false, [$symbol])]);
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
