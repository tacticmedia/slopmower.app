<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\FetchRepository;
use App\Application\ServerClone;
use App\Entity\Repository;
use App\GitHub\InstallationTokenProvider;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class InstallationTokenTest extends KernelTestCase
{
    use EnvironmentVariables;
    use GitHubRows;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('The token request asks for one repository and the permissions of the job')]
    public function testRequestLimitsTheToken(): void
    {
        // The recorded GitHub answers 201 only to a bearer token in JWT form that asks for one repository, with one
        // token per permission set.
        $tokens = self::getContainer()->get(InstallationTokenProvider::class);

        self::assertSame(GitHubResponses::INSTALLATION_TOKEN, $tokens->forRepository(5551, 812345, InstallationTokenProvider::CONTENTS_READ));
        self::assertSame(GitHubResponses::PULL_REQUESTS_INSTALLATION_TOKEN, $tokens->forRepository(5551, 812345, InstallationTokenProvider::PULL_REQUESTS_READ));
    }

    #[TestDox('Each fetch requests a new token')]
    public function testEachFetchRequestsANewToken(): void
    {
        $this->useLocalGitHub();
        $repository = $this->connectedRepository();
        $requestsBefore = self::gitHubRequestCount();

        self::runQueuedMessages();
        $requestsAfterClone = self::gitHubRequestCount();
        self::getContainer()->get(FetchRepository::class)->request($repository->getId());
        self::runQueuedMessages();
        self::getContainer()->get(FetchRepository::class)->fetchNow($repository->getId());

        self::assertSame(1, $requestsAfterClone - $requestsBefore, 'The clone requests one token.');
        self::assertSame(2, self::gitHubRequestCount() - $requestsAfterClone, 'The fetch job and the synchronous fetch request one token each.');
    }

    #[TestDox('The token is not stored in a cache pool or a service property')]
    public function testTokenIsNotKept(): void
    {
        $this->useLocalGitHub();
        $this->connectedRepository();

        self::runQueuedMessages();

        foreach ([InstallationTokenProvider::class, ServerClone::class] as $serviceId) {
            $service = self::getContainer()->get($serviceId);

            foreach (new \ReflectionObject($service)->getProperties() as $property) {
                $value = $property->getValue($service);
                self::assertStringNotContainsString(GitHubResponses::INSTALLATION_TOKEN, \is_object($value) ? $value::class : var_export($value, true), "{$serviceId}::\${$property->getName()}");
            }
        }

        $cacheDirectory = self::getContainer()->getParameter('kernel.cache_dir');

        $filesWithToken = [];

        foreach (new Finder()->files()->in($cacheDirectory) as $file) {
            if (str_contains($file->getContents(), GitHubResponses::INSTALLATION_TOKEN)) {
                $filesWithToken[] = $file->getPathname();
            }
        }

        self::assertSame([], $filesWithToken, 'No file of the cache directory, the cache pools included, holds the token.');
    }

    private function connectedRepository(): Repository
    {
        $this->gitHubSource('acme/billing');
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::storeInstallation($tenantId);
        self::storeCandidate($tenantId);

        return self::connectGitHub($tenantId);
    }

    /**
     * Every GitHub request of the kernel reaches this transport.
     */
    private static function gitHubRequestCount(): int
    {
        $transport = self::getContainer()->get('github.client.mock_transport');

        return $transport->getRequestsCount();
    }
}
