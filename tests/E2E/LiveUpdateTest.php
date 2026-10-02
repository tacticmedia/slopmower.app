<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\RequestScreens;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class LiveUpdateTest extends PantherTestCase
{
    use RequestScreens;
    use ResetDatabase;

    private const string REPLY = 'Should the new method be public?';

    private const int WAIT_SECONDS = 5;

    #[TestDox('An agent reply over MCP appears within 5 seconds without a reload')]
    public function testAgentReplyAppearsWithoutReload(): void
    {
        $this->codeViewReview();
        $requestId = $this->seededRequest(9, 12, 'Extract the tax calculation.')->toString();
        $agent = $this->agent();
        self::agentCalls($agent, 'claim_request', ['id' => $requestId]);
        $client = self::browserSignedInAs($this->commenter);
        self::openRequest($client, $requestId);
        // The local transport of the e2e hub keeps no history, so the subscription must exist before the reply.
        $client->waitFor('[data-live="connected"]');
        $client->executeScript('window.liveUpdateMarker = true;');

        self::agentCalls($agent, 'reply_to_request', ['id' => $requestId, 'body' => self::REPLY]);

        $client->waitForElementToContain(self::DETAIL.' [data-testid="thread"]', self::REPLY, self::WAIT_SECONDS);
        self::assertTrue($client->executeScript('return true === window.liveUpdateMarker;'), 'The page reloaded.');
    }
}
