<?php

declare(strict_types=1);

namespace App\Tests\Functional\Live;

use App\Application\IssueCommands;
use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueLinkKind;
use App\Domain\Issue\IssueStatus;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Live\PublishedUpdates;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IssueUpdateTest extends KernelTestCase
{
    use ApiFixtures;
    use PublishedUpdates;
    use ResetDatabase;

    #[TestDox('Each committed issue write publishes one update with the issue key to the topic of its project, a link one per issue, and a refused write none')]
    public function testEachIssueWritePublishesOneUpdate(): void
    {
        $user = TenantFixtures::of(self::getContainer())->create();
        $tenantId = $user->getTenantId();
        self::getContainer()->get(TenantContext::class)->set($tenantId);
        self::storeProject($tenantId, 'SM');
        self::storeProject($tenantId, 'DOC');
        $commands = self::getContainer()->get(IssueCommands::class);
        $agent = Actor::agent($user->getId(), 'claude-code laptop');
        [$issue, $other] = [new IssueKey('SM', 1), new IssueKey('DOC', 1)];

        $commands->create('SM', 'Round invoice totals once', IssueCategory::Change, 'Invoice totals round each line.', IssueStatus::Todo, $agent);
        $commands->create('DOC', 'Describe the rounding', IssueCategory::Change, null, IssueStatus::Draft, $agent);
        $commands->update($issue, null, null, '1. Add `TaxRounding::perInvoice()`.', null, null, null, $agent, 'billing-3f9a');
        $commands->comment($issue, 'The test is next.', CommentLabel::Comment, $agent, 'billing-3f9a');
        $commands->setStatus($issue, IssueStatus::InProgress, null, null, $agent, 'billing-3f9a');
        $commands->link($issue, $other, IssueLinkKind::Relates, 'DOC-1 describes it.', $agent);

        try {
            $commands->comment($issue, ' ', CommentLabel::Comment, $agent, 'billing-3f9a');
            self::fail('A blank comment is refused.');
        } catch (InvalidInput) {
        }

        self::assertSame(array_fill(0, 5, ['type' => 'issue', 'issueKey' => 'SM-1']), self::projectTopicUpdates($tenantId, 'SM'));
        self::assertSame(array_fill(0, 2, ['type' => 'issue', 'issueKey' => 'DOC-1']), self::projectTopicUpdates($tenantId, 'DOC'));
    }

    #[TestDox('An issue write inside an open transaction is refused and publishes nothing, so no update precedes a commit')]
    public function testWriteInsideATransactionIsRefused(): void
    {
        $user = TenantFixtures::of(self::getContainer())->create();
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());
        self::storeProject($user->getTenantId(), 'SM');
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            self::getContainer()->get(IssueCommands::class)->create('SM', 'Round invoice totals once', IssueCategory::Change, null, IssueStatus::Draft, Actor::agent($user->getId(), 'claude-code laptop'));
            self::fail('A write inside a transaction is refused.');
        } catch (\LogicException $refused) {
            self::assertSame('An issue write runs only outside a database transaction.', $refused->getMessage());
        } finally {
            $connection->rollBack();
        }

        self::assertSame([], self::projectTopicUpdates($user->getTenantId(), 'SM'));
    }
}
