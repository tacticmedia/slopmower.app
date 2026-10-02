<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\RequestScreens;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class ClosedRequestReadOnlyTest extends PantherTestCase
{
    use RequestScreens;
    use ResetDatabase;

    #[TestDox('A request of a closed review shows no action buttons and no reply box')]
    public function testRequestOfAClosedReviewIsReadOnly(): void
    {
        $this->codeViewReview();
        $requestId = $this->seededRequest(9, 12, 'Extract the tax calculation.')->toString();
        $this->closeReview();
        $client = self::browserSignedInAs($this->commenter);

        self::openRequest($client, $requestId);

        self::assertSelectorExists('[data-testid="review-closed"]');
        self::waitForStatus($client, 'Open');
        self::assertSelectorNotExists('[data-testid="request-actions"]');
        // The tab navigation always renders its hidden "More" button.
        self::assertSelectorNotExists(self::DETAIL.' > :not(nav) button');
        self::assertSelectorNotExists(self::DETAIL.' textarea');
    }
}
