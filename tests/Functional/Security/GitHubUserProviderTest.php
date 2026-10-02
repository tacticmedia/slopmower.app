<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Domain\User\SignInMethod;
use App\Entity\User;
use App\Security\GitHubUserProvider;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\GitHub\GitHubResponses;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class GitHubUserProviderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ResetDatabase;

    #[TestDox('The first GitHub sign-in creates one tenant and one user')]
    public function testFirstSignInCreatesTenantAndUser(): void
    {
        $user = $this->signIn();

        self::assertSame(SignInMethod::GitHub, $user->getSignInMethod());
        self::assertSame(1, $user->getGithubUserId());
        self::assertSame('octocat', $user->getLogin());
        self::assertSame('monalisa octocat', $user->getName());
        self::assertSame('https://github.com/images/error/octocat_happy.gif', $user->getAvatarUrl());
        self::assertSame(
            [['tenants' => 1, 'users' => 1]],
            self::getContainer()->get(Connection::class)->fetchAllAssociative(
                'SELECT (SELECT count(*) FROM tenant) AS tenants, (SELECT count(*) FROM app_user) AS users',
            ),
        );
    }

    #[TestDox('A later sign-in finds the same user and updates the profile')]
    public function testLaterSignInUpdatesTheProfile(): void
    {
        self::mockTime('2026-10-01 08:00:00');
        $first = $this->signIn();
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::mockTime('2026-10-02 09:00:00');

        $later = $this->signIn(GitHubResponses::RENAMED_USER_ACCESS_TOKEN);

        self::assertTrue($first->getId()->equals($later->getId()));
        self::assertSame(
            [[
                'tenants' => 1,
                'login' => 'monalisa',
                'name' => 'Mona Lisa Octocat',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4',
                'created_at' => '2026-10-01 08:00:00',
                'last_sign_in_at' => '2026-10-02 09:00:00',
            ]],
            self::getContainer()->get(Connection::class)->fetchAllAssociative(
                'SELECT (SELECT count(*) FROM tenant) AS tenants, login, name, avatar_url, created_at, last_sign_in_at FROM app_user',
            ),
        );
    }

    #[TestDox('A new user signs in with a login that another user held before')]
    public function testNewUserSignsInWithAFormerLogin(): void
    {
        $former = $this->signIn();
        self::getContainer()->get('doctrine')->getManager()->clear();

        $new = $this->signIn(GitHubResponses::FORMER_LOGIN_USER_ACCESS_TOKEN);

        self::assertFalse($former->getTenantId()->equals($new->getTenantId()));
        self::assertSame(
            [['github_user_id' => 1, 'login' => 'octocat'], ['github_user_id' => 2, 'login' => 'octocat']],
            self::getContainer()->get(Connection::class)->fetchAllAssociative(
                'SELECT github_user_id, login FROM app_user ORDER BY github_user_id',
            ),
        );
        self::assertSame(2, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM tenant'));
    }

    #[TestDox('A /user response without a user ID fails the sign-in and creates nothing')]
    public function testResponseWithoutUserIdFailsTheSignIn(): void
    {
        try {
            $this->signIn(GitHubResponses::REVOKED_ACCESS_TOKEN);
            self::fail('The sign-in did not fail.');
        } catch (AuthenticationException $exception) {
            self::assertSame('GitHub returned no user.', $exception->getMessage());
        }

        self::assertSame(
            [['tenants' => 0, 'users' => 0]],
            self::getContainer()->get(Connection::class)->fetchAllAssociative(
                'SELECT (SELECT count(*) FROM tenant) AS tenants, (SELECT count(*) FROM app_user) AS users',
            ),
        );
    }

    /**
     * The GitHub resource owner reads the recorded /user response of the access token.
     */
    private function signIn(string $accessToken = GitHubResponses::ACCESS_TOKEN): User
    {
        $response = self::getContainer()->get('hwi_oauth.resource_owner.github')->getUserInformation(['access_token' => $accessToken]);

        return self::getContainer()->get(GitHubUserProvider::class)->loadUserByOAuthUserResponse($response);
    }
}
