<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Actor\ActorKind;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    /**
     * The links come from the request, not from DEFAULT_URI.
     */
    #[Route('/api/v1/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser] User $user,
        Request $request,
        #[Autowire(env: 'APP_LOCAL_REPOSITORIES_DIR')] string $localRepositoriesDir,
    ): JsonResponse {
        $userId = $user->getId()->toString();

        return $this->json([
            'user' => [
                'id' => $userId,
                'login' => $user->getLogin(),
                'name' => $user->getName(),
                'avatarUrl' => $user->getAvatarUrl(),
                'signInMethod' => $user->getSignInMethod()->value,
            ],
            'tenantId' => $user->getTenantId()->toString(),
            'caller' => [
                'kind' => ActorKind::User->value,
                'userId' => $userId,
                'agentName' => null,
                'name' => $user->getLogin(),
                'avatarUrl' => $user->getAvatarUrl(),
            ],
            'token' => null,
            'features' => [
                'localRepositories' => '' !== $localRepositoriesDir,
                'githubApp' => null,
            ],
            'links' => [
                'mcpUrl' => $request->getUriForPath('/_mcp'),
                'docsUrl' => $request->getUriForPath('/docs'),
                'skillUrl' => $request->getUriForPath('/docs/skill/SKILL.md'),
            ],
        ]);
    }
}
