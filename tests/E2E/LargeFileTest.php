<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use App\Tests\Support\E2E\ShadowDom;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverElement;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class LargeFileTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const int MAXIMUM_RENDERED_ROWS = 300;

    /** True when the element is inside both the window and the code view's scroll container. */
    private const string IN_VIEWPORT = <<<'JS'
        const line = arguments[0].getBoundingClientRect();
        const view = document.querySelector('[data-testid="code-view"]').getBoundingClientRect();

        return line.top >= Math.max(view.top, 0) && line.bottom <= Math.min(view.bottom, window.innerHeight);
        JS;

    #[TestDox('#L3000 is in the viewport and fewer than 300 rows are rendered')]
    public function testDeepLinkScrollsAndRendersAFewRows(): void
    {
        $user = $this->user();
        $reviewId = self::startedReview($user, self::connectedRepository($user, $this->localSource(FixtureRepositories::codeView())), 'Large file');
        $client = self::browserSignedInAs($user);

        $client->request('GET', "/reviews/{$reviewId}/files/".FixtureRepositories::LARGE_FILE.'#L3000');

        $visibleText = ShadowDom::waitUntil(
            $client,
            '[data-line="3000"][data-selected-line]',
            static fn (WebDriverElement $line): ?string => true === $client->executeScript(self::IN_VIEWPORT, [$line]) ? $line->getText() : null,
        );

        self::assertStringContainsString('LINE_3000', $visibleText);
        self::assertLessThan(self::MAXIMUM_RENDERED_ROWS, ShadowDom::count($client, '[data-line]'));
    }
}
