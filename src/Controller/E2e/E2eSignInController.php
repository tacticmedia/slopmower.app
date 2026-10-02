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

#[When('e2e')]
final class E2eSignInController extends AbstractController
{
    #[Route('/_e2e/sign-in/{login}', name: 'e2e_sign_in', methods: ['GET'], env: 'e2e')]
    public function __invoke(string $login, LocalUserProvisioner $provisioner, Security $security): Response
    {
        $security->login($provisioner->provision($login), LocalSignInAuthenticator::class);

        return new Response("Signed in as {$login}", headers: ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
