<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\FullStackTestCase;
use App\Tests\Support\E2E\RequestScreens;
use PHPUnit\Framework\Attributes\TestDox;

final class LiveUpdateTest extends FullStackTestCase
{
    use RequestScreens;
    use ResetDatabase;

    private const string REPLY = 'Should the new method be public?';

    private const int WAIT_MILLISECONDS = 5000;

    #[TestDox('An agent reply over MCP appears within 5 seconds without a reload')]
    public function testAgentReplyAppearsWithoutReload(): void
    {
        $this->codeViewReview();
        $requestId = $this->seededRequest(9, 12, 'Extract the tax calculation.')->toString();
        $agent = $this->agent();
        self::agentCalls($agent, 'claim_request', ['id' => $requestId]);
        $this->signInAs($this->commenter->getLogin());
        $this->openRequest($requestId);
        // The local transport of the e2e hub keeps no history, so the subscription must exist before the reply.
        $this->expect($this->page->locator('[data-live="connected"]'))->toBeAttached();
        $this->page->evaluate('window.liveUpdateMarker = true');

        self::agentCalls($agent, 'reply_to_request', ['id' => $requestId, 'body' => self::REPLY]);

        $this->expect($this->page->locator(self::DETAIL.' [data-testid="thread"]'))->withTimeout(self::WAIT_MILLISECONDS)->toContainText(self::REPLY);
        self::assertTrue($this->page->evaluate('true === window.liveUpdateMarker'), 'The page reloaded.');
    }
}
