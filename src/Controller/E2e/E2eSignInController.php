<?php

declare(strict_types=1);

namespace App\Controller\E2e;

use App\Security\LocalSignInAuthenticator;
use App\Security\LocalUserProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Signs a Panther browser in as a local fixture user.
 */
#[When('e2e')]
final class E2eSignInController extends AbstractController
{
    #[Route('/_e2e/sign-in/{login}', name: 'e2e_sign_in', requirements: ['login' => '[A-Za-z0-9][A-Za-z0-9-]*'], methods: ['GET'], env: 'e2e')]
    public function __invoke(string $login, LocalUserProvisioner $provisioner, Security $security): Response
    {
        $user = $provisioner->signIn($login);

        return $security->login($user, LocalSignInAuthenticator::class, 'main') ?? $this->redirectToRoute('login');
    }
}
