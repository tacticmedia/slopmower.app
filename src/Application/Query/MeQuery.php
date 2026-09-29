<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Actor\ActorKind;
use App\Repository\UserRepository;
use App\Security\SessionUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A1, docs/specs/schemas/me.schema.json.
 */
final class MeQuery
{
    public function __construct(
        private readonly UserRepository $users,
        #[Autowire(env: 'APP_LOCAL_REPOSITORIES_DIR')]
        private readonly string $localRepositoriesDir,
    ) {
    }

    /**
     * @param string $baseUrl scheme, host, and base path of the current request, without a trailing slash
     *
     * @return array{
     *     user: array{id: string, login: string, name: ?string, avatarUrl: ?string, signInMethod: string},
     *     tenantId: string,
     *     caller: array{kind: string, userId: string, agentName: null, name: string, avatarUrl: ?string},
     *     token: null,
     *     features: array{localRepositories: bool, githubApp: null},
     *     links: array{mcpUrl: string, docsUrl: string, skillUrl: string},
     * }
     */
    public function forSession(SessionUser $sessionUser, string $baseUrl): array
    {
        $user = $this->users->find($sessionUser->userId()->toUlid())
            ?? throw new \LogicException('The signed-in user is not visible in the tenant context.');
        $userId = $user->getId()->toString();

        return [
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
            'features' => ['localRepositories' => '' !== $this->localRepositoriesDir, 'githubApp' => null],
            'links' => [
                'mcpUrl' => $baseUrl.'/_mcp',
                'docsUrl' => $baseUrl.'/docs',
                'skillUrl' => $baseUrl.'/docs/skill/SKILL.md',
            ],
        ];
    }
}
