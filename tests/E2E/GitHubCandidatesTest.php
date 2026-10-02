<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class GitHubCandidatesTest extends PantherTestCase
{
    use GitHubRows;
    use ResetDatabase;

    private const string SECTION = '[data-testid="github-candidates"]';

    #[TestDox('GitHub candidates and the Configure on GitHub link show on the repositories screen')]
    public function testCandidatesAndConfigureLinkShow(): void
    {
        $user = TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create();
        self::storeInstallation($user->getTenantId());
        self::storeCandidate($user->getTenantId());
        self::storeCandidate($user->getTenantId(), 812399, 'acme/shipping');
        $client = static::createPantherClient();
        $client->request('GET', '/_e2e/sign-in/octocat?githubUserId=1');

        $client->request('GET', '/repositories');

        $client->waitFor(self::SECTION.' button[aria-label="Connect acme/shipping"]');
        self::assertSelectorTextContains(self::SECTION.' table', 'acme/billing');
        self::assertSelectorAttributeContains(self::SECTION.' [data-testid="github-configure"]', 'href', 'https://github.com/apps/holistic-review-test/installations/new');
        self::assertSelectorTextContains(self::SECTION.' [data-testid="github-configure"]', 'Configure on GitHub');
    }
}
