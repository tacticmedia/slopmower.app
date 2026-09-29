<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\LoginEntryPoint;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class LoginEntryPointTest extends KernelTestCase
{
    #[TestDox('Outside /api/v1, the firewall entry point redirects to /login')]
    public function testMainEntryPointRedirectsToLogin(): void
    {
        $request = Request::create('/settings');
        $entryPointId = self::getContainer()->get(Security::class)->getFirewallConfig($request)?->getEntryPoint();
        self::assertSame(LoginEntryPoint::class, $entryPointId);

        $response = self::getContainer()->get(LoginEntryPoint::class)->start($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }
}
