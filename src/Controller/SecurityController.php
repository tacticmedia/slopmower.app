<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\LocalSignIn;
use App\Security\LocalSignInAuthenticator;
use App\Security\SessionUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(AuthenticationUtils $authenticationUtils, LocalSignIn $localSignIn, #[CurrentUser] ?SessionUser $user): Response
    {
        return $this->render('security/login.html.twig', [
            'signed_in_login' => $user?->login(),
            'local_login' => $localSignIn->login(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'csrf_parameter' => LocalSignInAuthenticator::CSRF_PARAMETER,
        ]);
    }

    #[Route(
        LocalSignInAuthenticator::PATH,
        name: 'login_local',
        methods: ['POST'],
        condition: "env('APP_LOCAL_SIGN_IN') !== ''",
        env: 'dev',
    )]
    public function localSignIn(): never
    {
        throw new \LogicException('LocalSignInAuthenticator handles this route.');
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The logout listener of the main firewall handles this route.');
    }
}
