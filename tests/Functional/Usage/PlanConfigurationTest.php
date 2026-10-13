<?php

declare(strict_types=1);

namespace App\Tests\Functional\Usage;

use App\Application\Plans;
use App\Tests\Support\Environment\EnvironmentVariables;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The check of `APP_PAID_PLANS` in {@see Plans}.
 */
final class PlanConfigurationTest extends KernelTestCase
{
    use EnvironmentVariables;

    #[TestDox('A paid plan with an invalid entry fails with an error that names the plan')]
    public function testInvalidPaidPlanFails(): void
    {
        $this->setEnvironmentVariable('APP_PAID_PLANS', '{"pro": {"name": "Pro", "marketplace_plan_id": 1313, "rank": 0, "repositories": 100, "open_reviews": 100, "requests_per_review": 5000, "repository_size_mib": 4096, "tenant_storage_mib": 51200}}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_PAID_PLANS: the plan "pro" needs a marketplace_plan_id and a rank of at least 1.');

        self::getContainer()->get(Plans::class);
    }

    #[TestDox('Two paid plans with the same rank fail with an error that names both')]
    public function testDuplicateRankFails(): void
    {
        $limits = '"repositories": 100, "open_reviews": 100, "requests_per_review": 5000, "repository_size_mib": 4096, "tenant_storage_mib": 51200';
        $this->setEnvironmentVariable('APP_PAID_PLANS', "{\"team\": {\"name\": \"Team\", \"marketplace_plan_id\": 1111, \"rank\": 1, {$limits}}, \"pro\": {\"name\": \"Pro\", \"marketplace_plan_id\": 1313, \"rank\": 1, {$limits}}}");

        $this->expectExceptionObject(new \InvalidArgumentException('APP_PAID_PLANS: the plans "team" and "pro" have the same rank 1.'));

        self::getContainer()->get(Plans::class);
    }
}
