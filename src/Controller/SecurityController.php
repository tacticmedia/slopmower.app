<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\LocalSignInAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(AuthenticationUtils $authenticationUtils, LocalSignInAuthenticator $localSignIn): Response
    {
        return $this->render('security/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'localLogin' => $localSignIn->localLogin(),
            'csrfTokenId' => LocalSignInAuthenticator::CSRF_TOKEN_ID,
        ]);
    }

    #[Route(LocalSignInAuthenticator::PATH, name: 'app_login_local', methods: ['POST'], condition: "env('APP_LOCAL_SIGN_IN') !== ''", env: 'dev')]
    public function localSignIn(): never
    {
        throw new \LogicException('LocalSignInAuthenticator handles this route.');
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The logout listener of the main firewall handles this route.');
    }
}
