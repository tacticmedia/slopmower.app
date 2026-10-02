<?php

declare(strict_types=1);

namespace App\Controller;

use App\Docs\DocsCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public /docs routes of docs/specs/rest-api-requests.md section 7. Decision D28.
 */
#[Route('/docs')]
final class DocsController extends AbstractController
{
    private const int SKILL_MAX_AGE_SECONDS = 300;

    public function __construct(
        private readonly DocsCatalog $catalog,
    ) {
    }

    #[Route('', name: 'docs_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page('index');
    }

    #[Route('/{slug}', name: 'docs_page', methods: ['GET'])]
    public function page(string $slug): Response
    {
        return $this->render('docs/page.html.twig', [
            'markdown' => $this->catalog->pageMarkdown($slug) ?? throw $this->createNotFoundException(),
        ]);
    }

    #[Route('/skill/SKILL.md', name: 'docs_skill', methods: ['GET'])]
    #[Cache(maxage: self::SKILL_MAX_AGE_SECONDS, public: true)]
    public function skill(Request $request, #[MapQueryParameter] bool $download = false): Response
    {
        $markdown = $this->catalog->skillMarkdown();
        $response = new Response($markdown, Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => \sprintf('%s; filename="SKILL.md"', $download ? 'attachment' : 'inline'),
        ]);
        $response->setEtag(hash('sha256', $markdown));
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/schemas/v1/{name}.schema.json', name: 'docs_schema', methods: ['GET'])]
    public function schema(string $name): Response
    {
        $path = $this->catalog->schemaPath($name) ?? throw $this->createNotFoundException();

        return new BinaryFileResponse($path, headers: ['Content-Type' => 'application/schema+json']);
    }
}
