<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\FullStackTestCase;
use App\Tests\Support\E2E\McpHttpClient;
use App\Tests\Support\E2E\TokenSettings;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpAssertions;
use PHPUnit\Framework\Attributes\TestDox;

final class ScopedTokenTest extends FullStackTestCase
{
    use ApiFixtures;
    use McpAssertions;
    use ResetDatabase;
    use TokenSettings;

    private const string TOKEN_NAME = 'codex desktop';

    #[TestDox('A token scoped to one repository shows that repository in the table')]
    public function testScopedTokenShowsItsRepository(): void
    {
        $this->openSettingsWithRepositories();

        $this->createToken(self::TOKEN_NAME, 'beta');
        $this->closeIssuedToken();

        self::assertSame('beta', $this->tokenCell(self::TOKEN_NAME, 'Scope'));
    }

    #[TestDox('list_repositories with that token returns only that repository')]
    public function testScopedTokenListsOnlyItsRepository(): void
    {
        $this->openSettingsWithRepositories();
        $secret = $this->createToken(self::TOKEN_NAME, 'beta');
        $mcp = McpHttpClient::connect($secret);

        $page = self::assertToolResult($mcp, $mcp->callTool('list_repositories'), 'list_repositories');

        self::assertIsArray($page['items']);
        self::assertSame(['beta'], array_column($page['items'], 'name'));
    }

    #[TestDox('The expiry date shows in the table')]
    public function testExpiryDateShowsInTheTable(): void
    {
        $this->openSettingsWithRepositories();
        /** @var array{string, string} $expiry Y-m-d and the browser's medium date format of the same day */
        $expiry = $this->page->evaluate(<<<'JS'
            () => {
                const now = new Date();
                const day = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 30);
                const isoDate = [day.getFullYear(), day.getMonth() + 1, day.getDate()].map((part) => String(part).padStart(2, '0')).join('-');

                return [isoDate, new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(day)];
            }
            JS);
        [$expiresOn, $shownDate] = $expiry;

        $this->createToken(self::TOKEN_NAME, expiresOn: $expiresOn);
        $this->closeIssuedToken();

        self::assertStringContainsString($shownDate, $this->tokenCell(self::TOKEN_NAME, 'Expiry'));
    }

    private function openSettingsWithRepositories(): void
    {
        $user = TenantFixtures::of(self::getContainer())->withLogin('jan')->create();
        self::storeRepository($user->getTenantId(), 'alpha');
        self::storeRepository($user->getTenantId(), 'beta');

        $this->openSettingsAs('jan');
    }
}
