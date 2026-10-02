<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\E2E\ShadowDom;
use App\Tests\Support\Git\FixtureRepositories;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class LineCommentTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    #[TestDox('A comment through + on line 10 is saved and shows on line 10 after a reload')]
    public function testLineCommentThroughPlusShowsOnItsLineAfterReload(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::waitForHighlighting($client, 10);

        self::dragFromPlus($client, 10, 10);
        self::addSingleComment($client, self::focusedCommentBoxBelow($client, 10), 'Use a named constant.', '[slot="annotation-10"]');

        self::assertSame([['kind' => 'line', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [10, 10], 'body' => 'Use a named constant.']], $this->savedRequests());
        self::assertSame('', $client->executeScript('return location.hash;'), 'The submit clears the selection and its line hash.');

        $client->reload();
        self::waitForSavedThread($client, '[slot="annotation-10"]', 'Use a named constant.');
        self::assertSame(1, ShadowDom::count($client, 'slot[name="annotation-10"]'));
    }
}
