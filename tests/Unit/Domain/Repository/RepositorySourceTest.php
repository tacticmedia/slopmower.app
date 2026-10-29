<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Repository;

use App\Domain\Repository\RepositorySource;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositorySourceTest extends KernelTestCase
{
    #[TestDox('Only a GitHub repository has pull requests')]
    public function testOnlyGitHubHasPullRequests(): void
    {
        $hasPullRequests = array_map(static fn (RepositorySource $source): bool => $source->hasPullRequests(), RepositorySource::cases());

        self::assertSame(['github' => true, 'local' => false], array_combine(array_column(RepositorySource::cases(), 'value'), $hasPullRequests));
    }
}
