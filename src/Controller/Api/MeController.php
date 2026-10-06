<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\GitHub\GitHubApp;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Security\AgentUser;
use App\Security\CurrentActor;
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
        #[CurrentUser] User|AgentUser $caller,
        CurrentActor $currentActor,
        UserRepository $users,
        ApiTokenRepository $tokens,
        GitHubApp $gitHubApp,
        Request $request,
        #[Autowire(env: 'APP_LOCAL_REPOSITORIES_DIR')] string $localRepositoriesDir,
    ): JsonResponse {
        $user = $caller instanceof AgentUser ? $users->find($caller->userId) : $caller;
        $token = $caller instanceof AgentUser ? $tokens->find($caller->tokenId) : null;
        \assert(null !== $user);
        $actor = $currentActor->get();
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
                'kind' => $actor->kind->value,
                'userId' => $userId,
                'agentName' => $actor->agentName,
                'name' => $actor->agentName ?? $user->getLogin(),
                'avatarUrl' => null === $actor->agentName ? $user->getAvatarUrl() : null,
            ],
            'token' => $token,
            'features' => [
                'localRepositories' => '' !== $localRepositoriesDir,
                'githubApp' => $gitHubApp->featuresFor($user),
            ],
            'links' => [
                'mcpUrl' => $request->getUriForPath('/_mcp'),
                'docsUrl' => $request->getUriForPath('/docs'),
                'skillUrl' => $request->getUriForPath('/docs/skill/SKILL.md'),
            ],
        ]);
    }
}
