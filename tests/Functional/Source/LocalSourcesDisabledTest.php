<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Application\ConnectedRepository;
use App\Application\ConnectLocalRepository;
use App\Source\LocalRepositoryDiscovery;
use App\Source\LocalRepositoryListing;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LocalSourcesDisabledTest extends KernelTestCase
{
    use EnvironmentVariables;
    use InvalidInputAssertions;
    use ResetDatabase;
    use ReviewDataFixtures;

    #[TestDox('With an empty variable, discovery reports disabled and connect gives invalid_input')]
    public function testEmptyVariableDisablesLocalSources(): void
    {
        $this->setEnvironmentVariable('APP_LOCAL_REPOSITORIES_DIR', '');
        self::enterNewTenant();
        $discovery = self::getContainer()->get(LocalRepositoryDiscovery::class);

        self::assertFalse($discovery->isEnabled());
        self::assertEquals(new LocalRepositoryListing([], false), $discovery->list());
        self::assertInvalidInput(['source'], static fn (): ConnectedRepository => self::getContainer()->get(ConnectLocalRepository::class)->connect('billing'));
    }
}
