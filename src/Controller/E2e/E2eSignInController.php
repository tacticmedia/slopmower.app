<?php

declare(strict_types=1);

namespace App\Controller\E2e;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LocalSignInAuthenticator;
use App\Security\LocalUserProvisioner;
use App\Tenancy\TenantFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

#[When('e2e')]
final class E2eSignInController extends AbstractController
{
    /**
     * With `githubUserId`, signs in the GitHub user that the test stored.
     */
    #[Route('/_e2e/sign-in/{login}', name: 'e2e_sign_in', methods: ['GET'], env: 'e2e')]
    public function __invoke(
        string $login,
        LocalUserProvisioner $provisioner,
        UserRepository $users,
        TenantFilter $tenantFilter,
        Security $security,
        #[MapQueryParameter] ?int $githubUserId = null,
    ): Response {
        $user = null === $githubUserId
            ? $provisioner->provision($login)
            : $tenantFilter->suspend(fn (): ?User => $users->findOneBy(['githubUserId' => $githubUserId, 'login' => $login])) ?? throw $this->createNotFoundException();
        $security->login($user, LocalSignInAuthenticator::class);

        return new Response("Signed in as {$login}", headers: ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
