<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\SessionUser;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextListener;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The listener alone, without the user provider, which also sets the tenant context when it refreshes a session user.
 */
final class TenantContextListenerTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('A main request with an authenticated user sets that user\'s tenant, and a sub-request or an anonymous request sets none')]
    public function testSetsTheTenantOfTheAuthenticatedUserOnMainRequests(): void
    {
        $kernel = self::bootKernel();
        $bob = TenantFixtures::fromContainer(self::getContainer())->localUser('bob');
        $listener = self::getContainer()->get(TenantContextListener::class);
        $tenantContext = self::getContainer()->get(TenantContext::class);

        $listener(new RequestEvent($kernel, Request::create('/login'), HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse(self::hasTenantContext($tenantContext));

        $user = SessionUser::fromUser($bob);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new PostAuthenticationToken($user, 'main', $user->getRoles()));

        $listener(new RequestEvent($kernel, Request::create('/login'), HttpKernelInterface::SUB_REQUEST));
        self::assertFalse(self::hasTenantContext($tenantContext));

        $listener(new RequestEvent($kernel, Request::create('/login'), HttpKernelInterface::MAIN_REQUEST));
        self::assertTrue($bob->getTenantId()->equals($tenantContext->tenantId()));
    }

    private static function hasTenantContext(TenantContext $tenantContext): bool
    {
        try {
            $tenantContext->tenantId();

            return true;
        } catch (MissingTenantContext) {
            return false;
        }
    }
}
