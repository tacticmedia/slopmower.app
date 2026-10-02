<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\EventStore\CommandFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantSequenceIsolationTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    #[TestDox('Each tenant has its own sequence')]
    public function testEachTenantHasItsOwnSequence(): void
    {
        $first = self::enterNewTenant();
        $firstRepository = self::newRepository($first);
        $second = self::enterNewTenant('octocat');
        $secondRepository = self::newRepository($second);

        foreach ([[$first, $firstRepository], [$second, $secondRepository], [$first, $firstRepository], [$second, $secondRepository], [$second, $secondRepository]] as [$tenantId, $repositoryId]) {
            self::writeIn($tenantId, $repositoryId);
        }

        self::assertSame([1, 2], self::seqs($first));
        self::assertSame([1, 2, 3], self::seqs($second));
    }

    private static function writeIn(TenantId $tenantId, RepositoryId $repositoryId): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenantId);
        self::startReview($repositoryId);
    }

    /**
     * @return list<int>
     */
    private static function seqs(TenantId $tenantId): array
    {
        $rows = self::storedRows($tenantId);
        self::assertSame(count($rows['review_event']), $rows['tenant'][0]['event_seq']);

        return array_map(static fn (array $event): int => $event['seq'], $rows['review_event']);
    }
}
