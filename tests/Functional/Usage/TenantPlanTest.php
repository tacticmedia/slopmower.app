<?php

declare(strict_types=1);

namespace App\Tests\Functional\Usage;

use App\Entity\User;
use App\Repository\MarketplaceAccountSql;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `plan` of A1, docs/specs/webhooks-and-live-updates.md section "Marketplace plans", with the plans of .env.test: Team
 * 1111 with rank 1, Pro 1313 with rank 2. The user octocat has the GitHub account 1, the installation of acme 9919.
 */
final class TenantPlanTest extends WebTestCase
{
    use GitHubRows;
    use JsonApiAssertions;
    use ResetDatabase;

    private const array FREE = ['name' => 'Free', 'trialEndsAt' => null, 'pendingChange' => null, 'listingUrl' => 'https://github.com/marketplace/slopmower-test'];

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create();
        self::storeInstallation($this->user->getTenantId());
        $this->client->loginUser($this->user, 'web');
    }

    #[TestDox('The plan of the tenant is the plan with the highest rank among the user account and the installation accounts')]
    public function testHighestRankWins(): void
    {
        self::storeAccount(1, 1111);
        self::storeAccount(9919, 1313, new \DateTimeImmutable('2026-10-20T00:00:00Z'), 1111, new \DateTimeImmutable('2026-11-01T00:00:00Z'));
        self::storeAccount(4, 1313);

        self::assertSame(
            ['name' => 'Pro', 'trialEndsAt' => '2026-10-20T00:00:00Z', 'pendingChange' => ['name' => 'Team', 'effectiveAt' => '2026-11-01T00:00:00Z'], 'listingUrl' => self::FREE['listingUrl']],
            $this->me()['plan'],
            'The account 4 belongs to no account of the tenant.',
        );
    }

    #[TestDox('A tenant without a record has the free plan')]
    public function testNoRecordGivesTheFreePlan(): void
    {
        self::assertSame(self::FREE, $this->me()['plan']);
    }

    #[TestDox('An unknown plan ID gives the free plan and logs an error')]
    public function testUnknownPlanIdGivesTheFreePlan(): void
    {
        self::storeAccount(9919, 4242);

        self::assertSame(self::FREE, $this->me()['plan']);
        self::assertTrue(self::getContainer()->get('monolog.handler.records')->hasErrorThatContains('The Marketplace plan 4242 is not a plan of the instance'));
    }

    #[TestDox('A paid plan raises the usage limits')]
    public function testPaidPlanRaisesTheLimits(): void
    {
        self::assertSame(25, $this->me()['usage'][0]['max'] ?? null);

        self::storeAccount(1, 1313);

        self::assertSame(['limit' => 'repositories', 'max' => 100, 'current' => 0], $this->me()['usage'][0] ?? null);
    }

    private static function storeAccount(int $accountId, int $planId, ?\DateTimeImmutable $trialEndsAt = null, ?int $pendingPlanId = null, ?\DateTimeImmutable $pendingEffectiveAt = null): void
    {
        self::getContainer()->get(MarketplaceAccountSql::class)->replace($accountId, $planId, 'monthly', $trialEndsAt, $pendingPlanId, $pendingEffectiveAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function me(): array
    {
        $this->client->request('GET', '/api/v1/me');

        return self::assertApiResponse($this->client->getResponse(), 200, 'me.schema.json');
    }
}
