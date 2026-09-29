<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Identity\RepositoryId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryUnavailableTest extends KernelTestCase
{
    #[TestDox('Returns the repository ID and the reason as details')]
    public function testReturnsItsValuesAsDetails(): void
    {
        $repositoryId = RepositoryId::generate();

        $error = new RepositoryUnavailable($repositoryId, RepositoryUnavailableReason::FetchFailed);

        self::assertSame(['repositoryId' => $repositoryId, 'reason' => RepositoryUnavailableReason::FetchFailed], $error->details());
    }

    /**
     * @return iterable<string, array{RepositoryUnavailableReason, string}>
     */
    public static function reasons(): iterable
    {
        yield 'cloning' => [RepositoryUnavailableReason::Cloning, 'Repository 01K6B2Z0R3P0S1T2V3W4X5Y6Z7 is still cloning.'];
        yield 'clone_failed' => [RepositoryUnavailableReason::CloneFailed, 'The clone of repository 01K6B2Z0R3P0S1T2V3W4X5Y6Z7 failed.'];
        yield 'fetch_failed' => [RepositoryUnavailableReason::FetchFailed, 'The fetch of repository 01K6B2Z0R3P0S1T2V3W4X5Y6Z7 failed.'];
        yield 'removed' => [RepositoryUnavailableReason::Removed, 'Access to repository 01K6B2Z0R3P0S1T2V3W4X5Y6Z7 was removed.'];
    }

    #[DataProvider('reasons')]
    #[TestDox('Names the repository and the reason in the message')]
    public function testNamesTheRepositoryAndTheReason(RepositoryUnavailableReason $reason, string $expectedMessage): void
    {
        $error = new RepositoryUnavailable(RepositoryId::fromString('01K6B2Z0R3P0S1T2V3W4X5Y6Z7'), $reason);

        self::assertSame($expectedMessage, $error->getMessage());
    }
}
