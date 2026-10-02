<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Entity\Repository;
use App\Entity\User;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in clients and rows for WebTestCase tests that use ResetDatabase.
 */
trait ApiFixtures
{
    private static function signIn(KernelBrowser $client, string $login = 'jan'): User
    {
        $user = TenantFixtures::of(self::getContainer())->withLogin($login)->create();
        $client->loginUser($user, 'web');

        return $user;
    }

    /**
     * A local repository row without a clone. Clears the entity manager, so a request reads it through the SQL filter.
     */
    private static function storeRepository(TenantId $tenantId, string $directory): Repository
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $repository = Repository::local($tenantId, RepositoryName::local($directory), $directory, Clock::get()->now());
        $entityManager->persist($repository);
        $entityManager->flush();
        $entityManager->clear();

        return $repository;
    }

    /**
     * A JSON request that passes the same-origin check unless $server overrides Sec-Fetch-Site.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server
     */
    private static function apiRequest(KernelBrowser $client, string $method, string $uri, ?array $body = null, array $server = []): Response
    {
        $client->request(
            $method,
            $uri,
            server: $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return $client->getResponse();
    }
}
