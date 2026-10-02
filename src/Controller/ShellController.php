<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The shell paths of docs/specs/rest-api-requests.md section 7. React Router renders the screens.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class ShellController extends AbstractController
{
    #[Route('/', name: 'shell_home', methods: ['GET'])]
    #[Route('/repositories', name: 'shell_repositories', methods: ['GET'])]
    #[Route('/repositories/{id}', name: 'shell_repository', methods: ['GET'])]
    #[Route('/reviews/{id}', name: 'shell_review', methods: ['GET'])]
    #[Route('/reviews/{id}/files/{path}', name: 'shell_review_file', requirements: ['path' => '.+'], methods: ['GET'])]
    #[Route('/reviews/{id}/requests', name: 'shell_review_requests', methods: ['GET'])]
    #[Route('/requests/{id}', name: 'shell_request', methods: ['GET'])]
    #[Route('/settings', name: 'shell_settings', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('shell.html.twig');
    }
}
