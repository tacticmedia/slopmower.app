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
    /** D94: Markdown of agents and READMEs loads no image from another host. `blob:` holds the README images of Browser only (D114). */
    private const string CONTENT_SECURITY_POLICY = "img-src 'self' data: blob: https://avatars.githubusercontent.com";

    #[Route('/', name: 'shell_home', methods: ['GET'])]
    #[Route('/repositories', name: 'shell_repositories', methods: ['GET'])]
    #[Route('/repositories/{id}', name: 'shell_repository', methods: ['GET'])]
    #[Route('/reviews/{id}', name: 'shell_review', methods: ['GET'])]
    #[Route('/reviews/{id}/files/{path}', name: 'shell_review_file', requirements: ['path' => '.+'], methods: ['GET'])]
    #[Route('/reviews/{id}/tree/{path}', name: 'shell_review_tree', requirements: ['path' => '.+'], methods: ['GET'])]
    #[Route('/reviews/{id}/blame/{path}', name: 'shell_review_blame', requirements: ['path' => '.+'], methods: ['GET'])]
    #[Route('/reviews/{id}/requests', name: 'shell_review_requests', methods: ['GET'])]
    #[Route('/requests/{id}', name: 'shell_request', methods: ['GET'])]
    #[Route('/projects', name: 'shell_projects', methods: ['GET'])]
    #[Route('/projects/{key}', name: 'shell_project', methods: ['GET'])]
    #[Route('/projects/{key}/comments', name: 'shell_project_comments', methods: ['GET'])]
    #[Route('/projects/{key}/graph', name: 'shell_project_graph', methods: ['GET'])]
    #[Route('/projects/{key}/settings', name: 'shell_project_settings', methods: ['GET'])]
    #[Route('/projects/{key}/issues/new', name: 'shell_issue_new', methods: ['GET'])]
    #[Route('/issues/{key}', name: 'shell_issue', methods: ['GET'])]
    #[Route('/settings', name: 'shell_settings', methods: ['GET'])]
    public function __invoke(): Response
    {
        $response = $this->render('shell.html.twig');
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);

        return $response;
    }
}
