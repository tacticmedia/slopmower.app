<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class RangeCommentTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    private const string BELOW_LINE_9 = '[slot="annotation-9"]';

    #[TestDox('A drag from 5 to 9, a drag from 9 to 5, and click with shift-click all give the anchor 5 to 9')]
    public function testEveryRangeGestureGivesTheSameAnchor(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::waitForHighlighting($client, 9);

        self::dragFromPlus($client, 5, 9);
        self::addSingleComment($client, self::focusedCommentBoxBelow($client, 9), 'Dragged down.', self::BELOW_LINE_9);

        self::dragFromPlus($client, 9, 5);
        self::addSingleComment($client, self::focusedCommentBoxBelow($client, 9), 'Dragged up.', self::BELOW_LINE_9);

        self::pointerGesture($client, static function (WebDriverActions $actions, callable $find): void {
            $actions->click($find(self::lineNumber(5)))->keyDown(null, WebDriverKeys::SHIFT)->click($find(self::lineNumber(9)))->keyUp(null, WebDriverKeys::SHIFT)->perform();
        });
        // The selection keeps the "+" on its last line, gap G1.
        self::dragFromPlus($client, 9, 9);
        self::addSingleComment($client, self::focusedCommentBoxBelow($client, 9), 'Shift-clicked.', self::BELOW_LINE_9);

        self::assertSame(
            [
                ['kind' => 'range', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [5, 9], 'body' => 'Dragged down.'],
                ['kind' => 'range', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [5, 9], 'body' => 'Dragged up.'],
                ['kind' => 'range', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [5, 9], 'body' => 'Shift-clicked.'],
            ],
            $this->savedRequests(),
        );
    }
}
