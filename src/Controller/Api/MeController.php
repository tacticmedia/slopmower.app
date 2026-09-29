<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\MeQuery;
use App\Security\SessionUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    /**
     * The links come from the current request, not from DEFAULT_URI.
     */
    #[Route('/api/v1/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] SessionUser $user, Request $request, MeQuery $query): JsonResponse
    {
        return $this->json(
            $query->forSession($user, $request->getSchemeAndHttpHost().$request->getBasePath()),
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }
}
