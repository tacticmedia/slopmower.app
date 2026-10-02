<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GitHubInstalledController extends AbstractController
{
    /**
     * The setup URL of the GitHub App. A new sign-in lists the installations again, then shows the repositories.
     */
    #[Route('/github/installed', name: 'github_installed', methods: ['GET'])]
    #[IsGranted(User::ROLE)]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('hwi_oauth_service_redirect', [
            'service' => 'github',
            '_target_path' => $this->generateUrl('shell_repositories'),
        ]);
    }
}
