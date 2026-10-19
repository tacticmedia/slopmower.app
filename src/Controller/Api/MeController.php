<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Plans;
use App\Application\Query\Preferences;
use App\Application\UsageLimits;
use App\Controller\Api\Input\PreferencesInput;
use App\Entity\User;
use App\GitHub\GitHubApp;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Security\AgentUser;
use App\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A1 and A4 of docs/specs/rest-api.md section 3.
 */
final class MeController extends AbstractController
{
    /**
     * The links come from the request, not from DEFAULT_URI.
     */
    #[Route('/api/v1/me', name: 'api_me', methods: ['GET'])]
    public function get(
        #[CurrentUser] User|AgentUser $caller,
        CurrentActor $currentActor,
        UserRepository $users,
        ApiTokenRepository $tokens,
        GitHubApp $gitHubApp,
        Plans $plans,
        UsageLimits $usageLimits,
        Request $request,
        #[Autowire(env: 'APP_LOCAL_REPOSITORIES_DIR')] string $localRepositoriesDir,
        #[Autowire(env: 'GITHUB_MARKETPLACE_URL')] string $marketplaceUrl,
    ): JsonResponse {
        $user = $caller instanceof AgentUser ? $users->find($caller->userId) : $caller;
        $token = $caller instanceof AgentUser ? $tokens->find($caller->tokenId) : null;
        \assert(null !== $user);
        $actor = $currentActor->get();
        $userId = $user->getId();
        $plan = $plans->ofTenant($user->getTenantId());

        return $this->json([
            'user' => [
                'id' => $userId,
                'login' => $user->getLogin(),
                'name' => $user->getName(),
                'avatarUrl' => $user->getAvatarUrl(),
                'signInMethod' => $user->getSignInMethod(),
            ],
            'tenantId' => $user->getTenantId(),
            'caller' => [
                'kind' => $actor->kind,
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
            'plan' => [
                'name' => $plan->plan->name,
                'trialEndsAt' => $plan->trialEndsAt,
                'pendingChange' => null === $plan->pendingPlan ? null : ['name' => $plan->pendingPlan->name, 'effectiveAt' => $plan->pendingEffectiveAt],
                'listingUrl' => '' === $marketplaceUrl ? null : $marketplaceUrl,
            ],
            'usage' => $usageLimits->usage($user->getTenantId()),
            'links' => [
                'mcpUrl' => $request->getUriForPath('/_mcp'),
                'docsUrl' => $request->getUriForPath('/docs'),
                'skillUrl' => $request->getUriForPath('/docs/skill/SKILL.md'),
            ],
            'preferences' => new Preferences($user->getCompletedTours()),
        ]);
    }

    #[Route('/api/v1/me/preferences', name: 'api_me_preferences', methods: ['PATCH'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function updatePreferences(
        #[MapRequestPayload(acceptFormat: 'json')] PreferencesInput $input,
        CurrentActor $currentActor,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $currentActor->sessionUser();

        if (null !== $input->completedTours) {
            $user->setCompletedTours($input->completedTours);
            $entityManager->flush();
        }

        return $this->json(new Preferences($user->getCompletedTours()));
    }
}
