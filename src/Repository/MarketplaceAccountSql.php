<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\TenantId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The `marketplace_account` records, docs/specs/webhooks-and-live-updates.md section "Marketplace plans". Raw SQL,
 * because the ORM has no `ON CONFLICT`, and a purchase delivery and the sign-in that follows it write one account at
 * the same time.
 */
final class MarketplaceAccountSql
{
    private const string REPLACE = <<<'SQL'
        INSERT INTO marketplace_account (account_id, plan_id, billing_cycle, trial_ends_at, pending_plan_id, pending_effective_at)
        VALUES (:accountId, :planId, :billingCycle, :trialEndsAt, :pendingPlanId, :pendingEffectiveAt)
        ON CONFLICT (account_id) DO UPDATE SET
            plan_id = EXCLUDED.plan_id,
            billing_cycle = EXCLUDED.billing_cycle,
            trial_ends_at = EXCLUDED.trial_ends_at,
            pending_plan_id = EXCLUDED.pending_plan_id,
            pending_effective_at = EXCLUDED.pending_effective_at
        SQL;
    private const string RECORD_PENDING_CHANGE = 'UPDATE marketplace_account SET pending_plan_id = :pendingPlanId, pending_effective_at = :pendingEffectiveAt WHERE account_id = :accountId';
    private const string DELETE = 'DELETE FROM marketplace_account WHERE account_id = :accountId';
    private const string OF_TENANT = <<<'SQL'
        SELECT plan_id, trial_ends_at, pending_plan_id, pending_effective_at FROM marketplace_account
        WHERE account_id IN (
            SELECT github_user_id FROM app_user WHERE tenant_id = :tenantId
            UNION SELECT account_id FROM github_installation WHERE tenant_id = :tenantId
        )
        SQL;

    private const array TYPES = ['trialEndsAt' => Types::DATETIME_IMMUTABLE, 'pendingEffectiveAt' => Types::DATETIME_IMMUTABLE];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Inserts or replaces the record of the account.
     *
     * @param string $billingCycle `monthly` or `yearly`
     */
    public function replace(int $accountId, int $planId, string $billingCycle, ?\DateTimeImmutable $trialEndsAt = null, ?int $pendingPlanId = null, ?\DateTimeImmutable $pendingEffectiveAt = null): void
    {
        $this->connection->executeStatement(self::REPLACE, [
            'accountId' => $accountId,
            'planId' => $planId,
            'billingCycle' => $billingCycle,
            'trialEndsAt' => self::utc($trialEndsAt),
            'pendingPlanId' => $pendingPlanId,
            'pendingEffectiveAt' => self::utc($pendingEffectiveAt),
        ], self::TYPES);
    }

    /**
     * Changes nothing without a record.
     *
     * @param \DateTimeImmutable|null $effectiveAt Null clears the pending change
     */
    public function recordPendingChange(int $accountId, ?int $pendingPlanId, ?\DateTimeImmutable $effectiveAt): void
    {
        $this->connection->executeStatement(
            self::RECORD_PENDING_CHANGE,
            ['accountId' => $accountId, 'pendingPlanId' => null === $effectiveAt ? null : $pendingPlanId, 'pendingEffectiveAt' => self::utc($effectiveAt)],
            self::TYPES,
        );
    }

    public function delete(int $accountId): void
    {
        $this->connection->executeStatement(self::DELETE, ['accountId' => $accountId]);
    }

    /**
     * The records of the GitHub accounts of the user and of the installations of the tenant.
     *
     * @return list<array{planId: int, trialEndsAt: ?\DateTimeImmutable, pendingPlanId: ?int, pendingEffectiveAt: ?\DateTimeImmutable}>
     */
    public function ofTenant(TenantId $tenantId): array
    {
        return array_map(
            static fn (array $row): array => [
                'planId' => (int) $row['plan_id'],
                'trialEndsAt' => self::time($row['trial_ends_at']),
                'pendingPlanId' => null === $row['pending_plan_id'] ? null : (int) $row['pending_plan_id'],
                'pendingEffectiveAt' => self::time($row['pending_effective_at']),
            ],
            $this->connection->fetchAllAssociative(self::OF_TENANT, ['tenantId' => $tenantId->toRfc4122()]),
        );
    }

    /**
     * The column has no time zone.
     */
    private static function utc(?\DateTimeImmutable $time): ?\DateTimeImmutable
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
