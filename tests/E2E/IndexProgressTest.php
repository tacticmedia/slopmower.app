<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class IndexProgressTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const string PROGRESS = '[data-testid="index-progress"]';

    #[TestDox('The progress bar shows and reaches ready')]
    public function testProgressBarShowsAndReachesReady(): void
    {
        $user = $this->user();
        $repository = self::connectedRepository($user, $this->localSource([
            'README.md' => "# Billing\n",
            'src/InvoiceTotals.php' => "<?php\n\nfinal class InvoiceTotals\n{\n    public function total(): int\n    {\n        return 0;\n    }\n}\n",
            'src/TaxRate.php' => "<?php\n\nenum TaxRate: int\n{\n    case Standard = 1000;\n}\n",
        ]));
        // The client boots a new kernel, which would drop the queued index job.
        $client = self::browserSignedInAs($user);
        // The index job stays in the queue of this process until runQueuedMessages().
        $reviewId = self::startedReview($user, $repository, 'Index review');
        $client->request('GET', "/reviews/{$reviewId}");
        $client->waitForVisibility(self::PROGRESS.'[data-state="pending"]');
        // The local transport of the e2e hub keeps no history, so the subscription must exist before the job runs.
        $client->waitFor('[data-live="connected"]');
        self::assertSelectorExists(self::PROGRESS.' [role="progressbar"][aria-valuenow="0"]');

        self::runQueuedMessages();

        $client->waitFor(self::PROGRESS.'[data-state="ready"]');
        self::assertSelectorTextSame(self::PROGRESS, 'Symbol index ready: 3 files, 4 symbols');
    }
}
