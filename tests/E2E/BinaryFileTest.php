<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use App\Tests\Support\E2E\ShadowDom;
use App\Tests\Support\Git\FixtureRepositories;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class BinaryFileTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    #[TestDox('A binary file shows the placeholder instead of code')]
    public function testBinaryFileShowsThePlaceholder(): void
    {
        $user = $this->user();
        $reviewId = self::startedReview($user, self::connectedRepository($user, $this->localSource(FixtureRepositories::codeView())), 'Binary file');
        $client = self::browserSignedInAs($user);

        $client->request('GET', "/reviews/{$reviewId}/files/".FixtureRepositories::BINARY_FILE);

        $client->waitFor('[data-testid="file-placeholder"]');
        self::assertSelectorTextSame('[data-testid="file-placeholder"]', 'Binary file not shown.');
        self::assertSelectorTextContains('[data-testid="file-header"]', FixtureRepositories::BINARY_FILE);
        self::assertSelectorNotExists(ShadowDom::CODE_HOST);
    }
}
