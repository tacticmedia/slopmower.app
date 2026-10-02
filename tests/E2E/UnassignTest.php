<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Reopen;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\RequestScreens;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class UnassignTest extends PantherTestCase
{
    use RequestScreens;
    use ResetDatabase;

    private const string CLAIMED_BY = self::DETAIL.' [data-testid="claimed-by"]';

    #[TestDox('Unassign restores the status before the claim and clears claimed by')]
    public function testUnassignRestoresTheStatusBeforeTheClaim(): void
    {
        $this->codeViewReview();
        $requestId = $this->seededRequest(9, 12, 'Extract the tax calculation.');
        $agent = $this->agent();
        self::agentCalls($agent, 'claim_request', ['id' => $requestId->toString()]);
        self::agentCalls($agent, 'mark_ready_for_review', ['id' => $requestId->toString(), 'note' => 'Extracted.']);
        $this->reopenedByTheUser($requestId, 'The method is still inline.');
        self::agentCalls($agent, 'claim_request', ['id' => $requestId->toString()]);
        $client = self::browserSignedInAs($this->commenter);
        self::openRequest($client, $requestId->toString());
        self::waitForStatus($client, 'In progress');
        self::assertSelectorTextContains(self::CLAIMED_BY, 'claude-code laptop');

        self::clickRequestButton($client, 'Unassign');
        self::submitNoteDialog($client, 'You seem stuck, I take this back.', 'Unassign');

        self::waitForStatus($client, 'Reopened');
        self::assertSelectorNotExists(self::CLAIMED_BY);
    }

    private function reopenedByTheUser(RequestId $requestId, string $note): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->commenter->getTenantId());
        self::getContainer()->get(RequestCommands::class)->reopen(new Reopen($requestId, $note), Actor::user($this->commenter->getId()));
    }
}
