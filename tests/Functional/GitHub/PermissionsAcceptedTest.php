<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Domain\Identity\TenantId;
use App\Message\CheckRunJob;
use App\Message\GitHubInstallationPermissionsJob;
use App\Messenger\TenantStamp;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LinkedPullRequests;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * `installation` `new_permissions_accepted`, docs/specs/webhooks-and-live-updates.md section 1, on the links of
 * {@see LinkedPullRequests}.
 */
final class PermissionsAcceptedTest extends KernelTestCase
{
    use GitHubRows;
    use LinkedPullRequests;
    use QueuedMessages;
    use ResetDatabase;

    #[TestDox('A new Pull requests: read queues the read of each linked pull request without a read state')]
    public function testNewPullRequestsReadQueuesReads(): void
    {
        $tenantId = self::linkPullRequests(self::CONTENTS)[0]->getTenantId();

        self::acceptPermissions($tenantId, self::PULL_REQUESTS);

        self::assertSame([42, 44], self::queuedReads());
        self::assertSame([], self::queued(CheckRunJob::class));
    }

    #[TestDox('A new Checks: write queues the check run of each record of a linked pull request and the read of each one without a read state')]
    public function testNewChecksWriteQueuesCheckRuns(): void
    {
        [$user, $recordIds] = self::linkPullRequests(self::PULL_REQUESTS);

        self::acceptPermissions($user->getTenantId(), self::CHECKS);

        self::assertEqualsCanonicalizing($recordIds, array_map(static fn (CheckRunJob $job): string => $job->pullRequestId->toString(), self::queued(CheckRunJob::class)));
        self::assertSame([42, 44], self::queuedReads());
    }

    #[TestDox('A delivery that adds neither permission queues nothing')]
    public function testOtherPermissionQueuesNothing(): void
    {
        $tenantId = self::linkPullRequests(self::CHECKS)[0]->getTenantId();

        self::acceptPermissions($tenantId, [...self::CHECKS, 'issues' => 'read']);

        self::assertSame([], self::asyncTransport()->getSent());
    }

    /**
     * Handles the job as the worker does, without running the jobs that it queues.
     *
     * @param array<string, string> $permissions
     */
    private static function acceptPermissions(TenantId $tenantId, array $permissions): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new GitHubInstallationPermissionsJob(5551, $permissions), [new TenantStamp($tenantId), new ReceivedStamp('async')]);
    }
}
