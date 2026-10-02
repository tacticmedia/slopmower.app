<?php

declare(strict_types=1);

namespace App\Tests\Functional\Anchoring;

use App\Application\Event\RepositoryFetched;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Error\CommitNotFound;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Request\Command\Open;
use App\Tests\Support\Anchoring\LocatorFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Architecture section 5.5, the symbol cases and a target outside the clone. The anchor is the method total(), lines 7
 * to 18 with its doc comment.
 */
final class LocatorSymbolAndTargetCasesTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use LocatorFixtures;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string TOTALS = 'src/Billing/InvoiceTotals.php';

    private const string TOTALS_PHP = <<<'PHP'
        <?php

        namespace App\Billing;

        final class InvoiceTotals
        {
            /**
             * Sum of all lines.
             */
            public function total(): int
            {
                $sum = 0;
                foreach ($this->lines as $line) {
                    $sum += $line;
                }

                return $sum;
            }

            public function tax(): int
            {
                return 0;
            }
        }

        PHP;

    private const string EDITED_BODY = '$sum += $line * 2;';

    /** 11 of 12 lines kept: 2 * 11 / (12 + 12). */
    private const float ONE_LINE_EDITED = 22 / 24;

    #[TestDox('A symbol found by its FQN gives its range at the target')]
    public function testSymbolFoundByItsFqnGivesItsRange(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::TOTALS_PHP]);
        $target = $this->commitAndFetch([self::TOTALS => self::edit("<?php\n\ndeclare(strict_types=1);", self::EDITED_BODY)]);

        self::assertSame(self::expected('changed', self::TOTALS, [9, 20]), self::actual($this->locate($this->totalAnchor($base), $target)));
    }

    #[TestDox('F12: a PHP syntax error at the target falls back to the line steps')]
    public function testSyntaxErrorFallsBackToLineSteps(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::TOTALS_PHP]);
        $target = $this->commitAndFetch([self::TOTALS => str_replace('return 0;', 'return 0', self::edit('<?php', self::EDITED_BODY))]);

        // The line similarity, where the symbol step would give 1.
        self::assertSame(self::expected('changed', self::TOTALS, [7, 18], self::ONE_LINE_EDITED), self::actual($this->locate($this->totalAnchor($base), $target)));
    }

    #[TestDox('F13: a renamed symbol gives changed or orphaned through the line steps')]
    public function testRenamedSymbolGoesThroughTheLineSteps(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::TOTALS_PHP]);
        $target = $this->commitAndFetch([self::TOTALS => str_replace('function total()', 'function sum()', self::TOTALS_PHP)]);

        self::assertSame(self::expected('changed', self::TOTALS, [7, 18], self::ONE_LINE_EDITED), self::actual($this->locate($this->totalAnchor($base), $target)));
    }

    #[TestDox('F14: a target commit that is not in the clone gives commit_not_found after one fetch')]
    public function testCommitOutsideTheCloneGivesCommitNotFound(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::TOTALS_PHP]);
        $container = self::getContainer();
        $actor = Actor::user($this->user->getId());
        $reviewId = $container->get(ReviewCommands::class)->start($this->repository->getId(), RefName::fromString($base->toString()), null, $actor);
        [$opened] = $container->get(RequestCommands::class)->open($reviewId, new Open($this->totalAnchor($base), 'Use `Money` instead of `int`.'), $actor);
        $fetches = 0;
        $container->get('event_dispatcher')->addListener(RepositoryFetched::class, static function () use (&$fetches): void {
            ++$fetches;
        });
        $missing = str_repeat('d', 40);

        try {
            $container->get(RequestQuery::class)->get($opened->requestId ?? self::fail('No request ID.'), $actor, CommitShaPrefix::fromString($missing));
            self::fail('No CommitNotFound.');
        } catch (CommitNotFound $error) {
            self::assertSame(['commit' => $missing], $error->details());
        }

        self::assertSame(1, $fetches);
    }

    private function totalAnchor(CommitSha $commit): Anchor
    {
        return $this->rangeAnchor($commit, self::TOTALS, 7, 18, SymbolRef::of('php', SymbolKind::Method, 'App\Billing\InvoiceTotals::total()'));
    }

    /**
     * @param string $opening Replaces `<?php`
     * @param string $body    Replaces the loop body of total()
     */
    private static function edit(string $opening, string $body): string
    {
        return str_replace(['<?php', '$sum += $line;'], [$opening, $body], self::TOTALS_PHP);
    }
}
